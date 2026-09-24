<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Actions\ReconcileGatewayPayments;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Gateway reconciliation (P2-34, §28.1).
 *
 * Notifications go missing — a payer closes the tab, an IPN is posted at a
 * server that was restarting — and a payment sits open while the money sits at
 * the provider. This is the pass that finds those, and every rule in it is
 * about not making things worse:
 *
 *   - only providers that publish a status lookup are asked;
 *   - a settled payment is never downgraded on a stale answer;
 *   - an outage changes nothing at all;
 *   - what the provider said is kept, redacted.
 */

beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    // bKash, which publishes no status lookup at all.
    $settings->define('payment.bkash.mode', 'payment', SettingType::String, 'sandbox');

    foreach (['base_url' => 'https://tokenized.example.test', 'app_key' => 'k', 'app_secret' => 's', 'username' => 'u', 'password' => 'p'] as $key => $value) {
        $settings->define("payment.bkash.sandbox.{$key}", 'payment', SettingType::String, $value, isEncrypted: true);
    }

    $this->account = testBusinessAccount(AccountStatus::Active);
});

function stalePayment(array $attributes = []): Payment
{
    $payment = Payment::create([
        'business_account_id' => test()->account->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => PaymentStatus::Initiated,
        'amount' => Money::fromDecimal('6000.00', Currency::BDT),
        'currency_code' => 'BDT',
        'gateway' => 'sslcommerz',
        ...$attributes,
    ]);

    // Old enough that an ordinary checkout would have finished either way.
    $payment->forceFill(['created_at' => now()->subHours(2)])->save();

    return $payment->refresh();
}

/**
 * SSLCommerz answering both calls the sweep makes.
 *
 * Two endpoints, deliberately faked separately. The sweep asks the transaction
 * query "what happened to my reference", and then hands anything paid to the
 * normal settlement path — which asks the **validator** all over again,
 * server-to-server, before anything is released. Faking only the first would
 * test a shortcut this code does not take.
 */
function queryAnswer(string $reference, string $status = 'VALID', string $amount = '6000.00'): void
{
    Http::fake([
        '*merchantTransIDvalidationAPI*' => Http::response([
            'APIConnect' => 'DONE',
            'no_of_trans_found' => 1,
            'element' => [[
                'status' => $status,
                'tran_id' => $reference,
                'val_id' => 'val-swept',
                'currency_amount' => $amount,
                'currency_type' => 'BDT',
                'bank_tran_id' => 'BANK-SWEPT',
            ]],
        ]),

        '*validationserverAPI*' => Http::response([
            'status' => $status,
            'tran_id' => $reference,
            'currency_amount' => $amount,
            'currency_type' => 'BDT',
            'bank_tran_id' => 'BANK-SWEPT',
        ]),
    ]);
}

describe('finding money the notifications missed', function () {
    it('settles a payment the provider says was paid', function () {
        $payment = stalePayment();

        queryAnswer($payment->reference);

        $summary = app(ReconcileGatewayPayments::class)->handle();

        expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and($summary['settled'])->toBe(1)
            ->and($payment->reconciliation_matched_at)->not->toBeNull();
    });

    it('goes through the normal settlement path rather than shortcutting it', function () {
        /*
         * The sweep is a trigger. The payment is verified again
         * server-to-server, under the settlement lock, with the amount and
         * identity checks — so a provider reporting a different amount still
         * cannot settle it.
         */
        $payment = stalePayment();

        queryAnswer($payment->reference, amount: '10.00');

        app(ReconcileGatewayPayments::class)->handle();

        expect($payment->refresh()->status)->not->toBe(PaymentStatus::Paid);
    });

    it('leaves a payment the provider agrees is unpaid alone', function () {
        // Closing it is the payment deadline's job (§9), which knows about
        // grace periods and coupon holds this sweep has no business in.
        $payment = stalePayment();

        queryAnswer($payment->reference, status: 'FAILED');

        app(ReconcileGatewayPayments::class)->handle();

        expect($payment->refresh()->status)->toBe(PaymentStatus::Initiated)
            ->and($payment->reconciliation_checked_at)->not->toBeNull();
    });

    it('does not ask about a payment that is still fresh', function () {
        // An ordinary checkout is still in progress. Asking now would be asking
        // about something nobody has had time to finish.
        Payment::create([
            'business_account_id' => $this->account->id,
            'purpose' => PaymentPurpose::Activation,
            'status' => PaymentStatus::Initiated,
            'amount' => Money::fromDecimal('6000.00', Currency::BDT),
            'currency_code' => 'BDT',
            'gateway' => 'sslcommerz',
        ]);

        Http::fake();

        expect(app(ReconcileGatewayPayments::class)->handle()['checked'])->toBe(0);

        Http::assertNothingSent();
    });
});

describe('never downgrading what is already settled', function () {
    it('does not un-pay a settled payment on a stale answer', function () {
        /*
         * The rule this exists for. A provider answering `FAILED` about a
         * payment we have confirmed is a discrepancy for a person — and a stale
         * answer that un-paid something would be far worse than one that was
         * never acted on.
         */
        $payment = stalePayment([
            'status' => PaymentStatus::Paid,
            'gateway_reference' => 'val-1',
            'completed_at' => now(),
        ]);

        queryAnswer($payment->reference, status: 'FAILED');

        $summary = app(ReconcileGatewayPayments::class)->handle();

        expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and($summary['mismatched'])->toBe(1)
            ->and($payment->reconciliation_matched_at)->toBeNull();
    });

    it('reports an amount the provider disagrees with, without changing it', function () {
        $payment = stalePayment([
            'status' => PaymentStatus::Paid,
            'gateway_reference' => 'val-1',
            'completed_at' => now(),
        ]);

        queryAnswer($payment->reference, amount: '10.00');

        $summary = app(ReconcileGatewayPayments::class)->handle();

        expect($summary['mismatched'])->toBe(1)
            ->and($payment->refresh()->amount->toDecimal())->toBe('6000.00')
            ->and($payment->status)->toBe(PaymentStatus::Paid);
    });

    it('records agreement so it stops asking', function () {
        $payment = stalePayment([
            'status' => PaymentStatus::Paid,
            'gateway_reference' => 'val-1',
            'completed_at' => now(),
        ]);

        queryAnswer($payment->reference);

        app(ReconcileGatewayPayments::class)->handle();

        expect($payment->refresh()->reconciliation_matched_at)->not->toBeNull();

        // A second pass has nothing to look at.
        Http::fake();

        expect(app(ReconcileGatewayPayments::class)->handle()['checked'])->toBe(0);
    });
});

describe('an outage changes nothing', function () {
    it('leaves every payment exactly as it was', function () {
        /*
         * "We could not ask" is not an answer. Not even the checked timestamp
         * moves, so the next pass tries again rather than treating an outage as
         * a look.
         */
        $payment = stalePayment();

        Http::fake(['*' => Http::response('', 503)]);

        $summary = app(ReconcileGatewayPayments::class)->handle();

        $payment->refresh();

        expect($payment->status)->toBe(PaymentStatus::Initiated)
            ->and($payment->reconciliation_checked_at)->toBeNull()
            ->and($summary['unreachable'])->toBe(1);
    });

    it('keeps going when one payment fails', function () {
        // One unreachable provider must not leave every payment after it
        // unchecked.
        stalePayment();
        stalePayment();

        Http::fake(['*' => Http::response('', 503)]);

        expect(app(ReconcileGatewayPayments::class)->handle()['checked'])->toBe(2);
    });
});

describe('only providers that can be asked', function () {
    it('skips a provider that publishes no status lookup', function () {
        /*
         * bKash has none — its execute call is both the completion and the
         * answer, and a payment id executes once. Calling something else
         * instead would be inventing a protocol for somebody else's system.
         */
        stalePayment(['gateway' => 'bkash']);

        Http::fake();

        $summary = app(ReconcileGatewayPayments::class)->handle();

        expect($summary['checked'])->toBe(1)
            ->and($summary['settled'])->toBe(0);

        Http::assertNothingSent();
    });

    it('skips a payment that never reached a gateway', function () {
        stalePayment(['gateway' => null]);

        Http::fake();

        app(ReconcileGatewayPayments::class)->handle();

        Http::assertNothingSent();
    });
});

describe('the record', function () {
    it('keeps what the provider said, redacted', function () {
        $payment = stalePayment();

        queryAnswer($payment->reference);

        app(ReconcileGatewayPayments::class)->handle();

        $entry = PaymentLog::query()->where('event', 'reconcile')->firstOrFail();

        expect($entry->payment_id)->toBe($payment->id)
            ->and($entry->outcome)->toBe('paid')
            ->and(json_encode($entry->context))->not->toContain('pass');
    });

    it('shouts about a mismatch rather than only writing a row', function () {
        // A number that has been quietly wrong for a fortnight is the failure
        // mode of a financial system, so this one is loud.
        $payment = stalePayment([
            'status' => PaymentStatus::Paid,
            'gateway_reference' => 'val-1',
            'completed_at' => now(),
        ]);

        queryAnswer($payment->reference, status: 'FAILED');

        Log::shouldReceive('channel')->with('payment')->andReturnSelf();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('critical')->once()->withArgs(
            fn (string $message) => str_contains($message, 'does not match its provider'),
        );

        app(ReconcileGatewayPayments::class)->handle();
    });
});
