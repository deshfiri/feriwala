<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Actions\ExpireUnpaidPayments;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * SSLCommerz wired into the payment flow (P1-52, §26.4).
 *
 * Three separate places a gateway can send somebody back to, one IPN that is the
 * reliable half, and one rule underneath all of it: nothing releases value on
 * the strength of a redirect or a webhook body. The gateway is asked directly,
 * every time.
 */

function gatewayFlowSettings(string $storePassword = 'pass'): void
{
    $settings = app(SettingsRepository::class);

    $settings->define('billing.registration_fee', 'billing', SettingType::Money, 100000);
    $settings->define('billing.gateway_charge_percent', 'billing', SettingType::Decimal, '0');
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, $storePassword, isEncrypted: true);
}

/**
 * The signed IPN body SSLCommerz would post.
 *
 * @return array<string, string>
 */
function gatewayFlowSignedIpn(string $reference, string $valId = 'val-1'): array
{
    $fields = [
        'tran_id' => $reference,
        'val_id' => $valId,
        'status' => 'VALID',
    ];

    $signed = $fields;
    $signed['store_passwd'] = md5('pass');
    ksort($signed);

    $pairs = [];

    foreach ($signed as $key => $value) {
        $pairs[] = $key.'='.$value;
    }

    return $fields + [
        'verify_key' => 'tran_id,val_id,status',
        'verify_sign' => md5(implode('&', $pairs)),
    ];
}

beforeEach(function () {
    gatewayFlowSettings();

    $this->account = testBusinessAccount(AccountStatus::PackageSelectionPending);
    $this->applicant = $this->account->owner;

    $this->package = Package::create([
        'name' => 'Growth',
        'slug' => 'growth',
        'fee_minor' => 500000,
        'validity_days' => 365,
    ]);

    /*
     * One stub for the whole test, answering from mutable state.
     *
     * Deliberately not a helper called twice: `Http::fake()` **merges** stubs
     * rather than replacing them and the first matching pattern wins, so a
     * second call setting up a different answer is silently ignored. A single
     * closure reading `$this->validation` is the version that does what it
     * looks like it does.
     */
    $this->validation = [
        'status' => 'VALID',
        'currency_amount' => '6000.00',
        'currency_type' => 'BDT',
    ];

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'gwprocess')) {
            return Http::response([
                'status' => 'SUCCESS',
                'GatewayPageURL' => 'https://pay.test/go',
                'sessionkey' => 'session-1',
            ]);
        }

        // An integer stands for "the gateway answered with this HTTP status",
        // which is how an outage is expressed.
        return is_int($this->validation)
            ? Http::response('', $this->validation)
            : Http::response($this->validation);
    });

    $this->start = function (): Payment {
        $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
        $this->actingAs($this->applicant)->post(route('checkout.pay'), ['gateway' => 'sslcommerz']);

        $payment = Payment::query()->firstOrFail();

        // The gateway answers about the payment under test unless a test says
        // otherwise: a mismatch has to be asked for, not arrived at.
        $this->validation['tran_id'] = $payment->reference;

        return $payment;
    };
});

describe('starting a payment', function () {
    it('sends the gateway the stored amount, never a submitted one', function () {
        // §36.1: a total in the request is the obvious thing to edit.
        $this->actingAs($this->applicant)->post(route('packages.select', $this->package));
        $this->actingAs($this->applicant)->post(route('checkout.pay'), [
            'gateway' => 'sslcommerz',
            'total_amount' => '1.00',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'gwprocess')
            && $request['total_amount'] === '6000.00');

        expect(Payment::query()->firstOrFail()->amount_minor->minorUnits)->toBe(600000);
    });

    it('gives the gateway three separate places to send somebody back to', function () {
        /*
         * One shared URL would throw away the only thing the gateway tells us
         * for free — which of the three happened — and leave us reading it out
         * of a status field the browser could have edited.
         */
        ($this->start)();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'gwprocess')) {
                return false;
            }

            return $request['success_url'] === route('checkout.return')
                && $request['fail_url'] === route('checkout.failed')
                && $request['cancel_url'] === route('checkout.cancelled')
                && $request['ipn_url'] === route('webhooks.payment', 'sslcommerz');
        });
    });

    it('never puts a credential anywhere the applicant can see', function () {
        app(SettingsRepository::class)
            ->set('payment.sslcommerz.sandbox.store_password', 'merchant-secret-abc123');

        $this->actingAs($this->applicant)->post(route('packages.select', $this->package));

        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertDontSee('merchant-secret-abc123', escape: false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('gateways.0.name', 'sslcommerz')
                ->missing('gateways.0.store_id'),
            );
    });
});

describe('coming back from the gateway', function () {
    it('settles only after asking the gateway directly', function () {
        $payment = ($this->start)();

        $this->actingAs($this->applicant)
            ->get(route('checkout.return', ['tran_id' => $payment->reference, 'val_id' => 'val-1']))
            ->assertRedirect(route('onboarding.status'));

        expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and($payment->gateway_reference)->toBe('val-1');
    });

    it('believes the gateway rather than the redirect', function () {
        // The browser says it worked; SSLCommerz says it did not.
        $payment = ($this->start)();

        $this->validation = ['status' => 'FAILED', 'tran_id' => $payment->reference];

        $this->actingAs($this->applicant)
            ->get(route('checkout.return', [
                'tran_id' => $payment->reference,
                'val_id' => 'val-1',
                'status' => 'VALID',
            ]))
            ->assertRedirect(route('checkout.show'));

        expect($payment->refresh()->status)->toBe(PaymentStatus::Failed);
    });

    it('refuses a transaction that belongs to a different payment', function () {
        /*
         * The callback says which payment to look at; the gateway says which
         * payment the transaction is really for. Without comparing the two, a
         * signed notification pointed at the wrong order settles it with
         * somebody else's money.
         */
        $payment = ($this->start)();

        $this->validation['tran_id'] = 'PAY-SOMEBODY-ELSE';

        $this->actingAs($this->applicant)
            ->get(route('checkout.return', ['tran_id' => $payment->reference, 'val_id' => 'val-1']));

        expect($payment->refresh()->status)->toBe(PaymentStatus::Initiated);
    });

    it('refuses an amount the gateway reports differently', function () {
        $payment = ($this->start)();

        $this->validation['currency_amount'] = '1.00';

        $this->actingAs($this->applicant)
            ->get(route('checkout.return', ['tran_id' => $payment->reference, 'val_id' => 'val-1']));

        expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
            ->and($this->account->fresh()->status)->not->toBe(AccountStatus::Active);
    });

    it('refuses a currency the gateway settled in instead', function () {
        // The same figure in a different currency is not the same money (D4).
        $payment = ($this->start)();

        $this->validation['currency_type'] = 'USD';

        $this->actingAs($this->applicant)
            ->get(route('checkout.return', ['tran_id' => $payment->reference, 'val_id' => 'val-1']));

        expect($payment->refresh()->status)->toBe(PaymentStatus::Failed);
    });

    it('says it is checking rather than failing when the gateway is unreachable', function () {
        $payment = ($this->start)();

        $this->validation = 503;

        $this->actingAs($this->applicant)
            ->get(route('checkout.return', ['tran_id' => $payment->reference, 'val_id' => 'val-1']))
            ->assertRedirect(route('onboarding.status'));

        // Left retryable. The IPN will settle it.
        expect($payment->refresh()->status)->toBe(PaymentStatus::Initiated);
    });

    it('cannot be pointed at somebody else\'s payment', function () {
        // §31.3 as a query concern: scoped to the caller's own account, so a
        // stranger's reference finds nothing rather than finding their payment.
        $payment = ($this->start)();

        $stranger = testBusinessAccount(AccountStatus::PaymentPending);

        $this->actingAs($stranger->owner)
            ->get(route('checkout.failed', ['tran_id' => $payment->reference]));

        expect($payment->refresh()->status)->toBe(PaymentStatus::Initiated);
    });
});

describe('cancelling and failing', function () {
    it('closes the attempt when somebody backs out at the gateway', function () {
        $payment = ($this->start)();

        $this->actingAs($this->applicant)
            ->get(route('checkout.cancelled', ['tran_id' => $payment->reference]))
            ->assertRedirect(route('checkout.show'));

        expect($payment->refresh()->status)->toBe(PaymentStatus::Cancelled)
            ->and($payment->cancelled_at)->not->toBeNull();
    });

    it('records a failure the gateway reported', function () {
        $payment = ($this->start)();

        $this->actingAs($this->applicant)
            ->get(route('checkout.failed', ['tran_id' => $payment->reference]));

        expect($payment->refresh()->status)->toBe(PaymentStatus::Failed);
    });

    it('never lets a failure redirect undo money that arrived', function () {
        /*
         * The browser is trusted to close an attempt nobody paid for, and never
         * to open or reopen one. A forged failure URL must not be able to
         * un-settle a payment.
         */
        $payment = ($this->start)();

        app(SettlePayment::class)->handle($payment, 'val-1');

        $this->actingAs($this->applicant)
            ->get(route('checkout.failed', ['tran_id' => $payment->reference]));

        expect($payment->refresh()->status)->toBe(PaymentStatus::Paid);
    });
});

describe('the IPN', function () {
    it('settles a signed notification after verifying it', function () {
        $payment = ($this->start)();

        $this->post(
            route('webhooks.payment', 'sslcommerz'),
            gatewayFlowSignedIpn($payment->reference),
        )->assertOk();

        expect($payment->refresh()->status)->toBe(PaymentStatus::Paid);
    });

    it('settles once however many times it is delivered', function () {
        // A gateway retries. Settling twice would activate twice.
        $payment = ($this->start)();

        $body = gatewayFlowSignedIpn($payment->reference);

        $this->post(route('webhooks.payment', 'sslcommerz'), $body)->assertOk();
        $this->post(route('webhooks.payment', 'sslcommerz'), $body)->assertOk();

        expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and(Payment::query()->count())->toBe(1);
    });

    it('refuses to let one transaction settle two payments', function () {
        /*
         * The provider's transaction identity belongs to one payment. Two
         * payments claiming it would be one payment of real money settling two
         * orders — refused here with a readable answer, and refused again by
         * the unique index underneath.
         */
        $first = ($this->start)();

        app(SettlePayment::class)->handle($first, 'val-shared');

        $other = testBusinessAccount(AccountStatus::PaymentPending);
        $second = Payment::create([
            'business_account_id' => $other->id,
            'purpose' => $first->purpose,
            'status' => PaymentStatus::Initiated,
            'amount_minor' => 600000,
            'currency_code' => 'BDT',
            'gateway' => 'sslcommerz',
        ]);

        $this->validation['tran_id'] = $second->reference;

        app(SettlePayment::class)->handle($second, 'val-shared');

        expect($second->refresh()->status)->toBe(PaymentStatus::Initiated)
            ->and($first->refresh()->gateway_reference)->toBe('val-shared');
    });

    it('tells the gateway to retry when verification is unavailable', function () {
        $payment = ($this->start)();

        $this->validation = 503;

        $this->post(
            route('webhooks.payment', 'sslcommerz'),
            gatewayFlowSignedIpn($payment->reference),
        )->assertStatus(503);

        expect($payment->refresh()->status)->toBe(PaymentStatus::Initiated);
    });

    it('refuses an unsigned notification before looking anything up', function () {
        $payment = ($this->start)();

        $this->postJson(route('webhooks.payment', 'sslcommerz'), [
            'tran_id' => $payment->reference,
            'val_id' => 'val-1',
            'status' => 'VALID',
        ])->assertStatus(401);

        expect($payment->refresh()->status)->toBe(PaymentStatus::Initiated);
    });
});

describe('a success that arrives too late', function () {
    beforeEach(function () {
        $this->expired = function (): Payment {
            $payment = ($this->start)();

            Payment::query()->update(['expires_at' => now()->subHour()]);
            app(ExpireUnpaidPayments::class)->handle();

            return $payment->refresh();
        };
    });

    it('records the money without reviving the purchase', function () {
        /*
         * The P1.E gap. A checkout expires, the gateway confirms afterwards.
         * Both facts are true: the money is real, and the purchase is over.
         * Neither may be discarded, and the second must not be rewritten by
         * the first.
         */
        $payment = ($this->expired)();

        expect($payment->status)->toBe(PaymentStatus::Cancelled);

        $this->post(
            route('webhooks.payment', 'sslcommerz'),
            gatewayFlowSignedIpn($payment->reference),
        )->assertOk();

        $payment->refresh();

        expect($payment->status)->toBe(PaymentStatus::ReconciliationRequired)
            ->and($payment->needsReconciliation())->toBeTrue()
            // The verified event is persisted, not merely logged.
            ->and($payment->gateway_reference)->toBe('val-1')
            ->and((int) $payment->settled_amount_minor)->toBe(600000)
            ->and($payment->settled_currency_code)->toBe('BDT')
            ->and($payment->reconciliation_required_at)->not->toBeNull()
            ->and($payment->reconciliation_reason)->toContain('after the checkout');
    });

    it('activates nothing', function () {
        $payment = ($this->expired)();

        $this->post(route('webhooks.payment', 'sslcommerz'), gatewayFlowSignedIpn($payment->reference));

        expect($this->account->fresh()->status)->toBe(AccountStatus::PaymentPending)
            ->and(UserPackage::query()->firstOrFail()->status)
            ->toBe(UserPackageStatus::PendingPayment);
    });

    it('leaves the expired invoice saying exactly what it said', function () {
        // The document is not rewritten to match a payment that came too late.
        $payment = ($this->start)();
        $invoice = Invoice::query()->firstOrFail();
        $before = $invoice->total_minor->minorUnits;

        Payment::query()->update(['expires_at' => now()->subHour()]);
        app(ExpireUnpaidPayments::class)->handle();

        $this->post(route('webhooks.payment', 'sslcommerz'), gatewayFlowSignedIpn($payment->reference));

        expect($invoice->fresh()->total_minor->minorUnits)->toBe($before)
            ->and($invoice->fresh()->load('payment')->isPaid())->toBeFalse();
    });

    it('flags it once however many times the gateway retries', function () {
        $payment = ($this->expired)();

        $body = gatewayFlowSignedIpn($payment->reference);

        $this->post(route('webhooks.payment', 'sslcommerz'), $body)->assertOk();
        $flaggedAt = $payment->refresh()->reconciliation_required_at;

        $this->post(route('webhooks.payment', 'sslcommerz'), $body)->assertOk();

        expect($payment->refresh()->reconciliation_required_at?->toIso8601String())
            ->toBe($flaggedAt?->toIso8601String());
    });

    it('does not flag a late failure', function () {
        // Only confirmed money needs a person. A gateway saying "no" after a
        // cancellation is agreeing with us.
        $payment = ($this->expired)();

        $this->validation = ['status' => 'FAILED', 'tran_id' => $payment->reference];

        $this->post(route('webhooks.payment', 'sslcommerz'), gatewayFlowSignedIpn($payment->reference));

        expect($payment->refresh()->status)->toBe(PaymentStatus::Cancelled);
    });

    it('tells the applicant not to pay again', function () {
        /*
         * The one state where "try again" is the wrong offer: somebody who has
         * already paid must not be invited to pay twice.
         */
        $payment = ($this->expired)();

        $this->post(route('webhooks.payment', 'sslcommerz'), gatewayFlowSignedIpn($payment->reference));

        $this->actingAs($this->applicant)
            ->get(route('checkout.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deadline.reconciling', true),
            );

        // And refused on the server, where it counts.
        $this->actingAs($this->applicant)
            ->post(route('checkout.pay'), ['gateway' => 'sslcommerz'])
            ->assertSessionHasErrors('gateway');
    });

    it('never resolves itself into a settled payment', function () {
        // The status map gives it no way out. A refund is the way out, and that
        // is somebody's decision, not a callback's.
        $payment = ($this->expired)();

        $this->post(route('webhooks.payment', 'sslcommerz'), gatewayFlowSignedIpn($payment->reference));

        expect($payment->refresh()->canTransitionTo(PaymentStatus::Paid))->toBeFalse()
            ->and($payment->isSettled())->toBeFalse();
    });

    it('does not throw when the money cannot be placed', function () {
        // A late callback ending as an exception would leave confirmed money
        // with no durable record anywhere.
        $payment = ($this->expired)();

        expect(fn () => app(SettlePayment::class)->handle($payment, 'val-1'))
            ->not->toThrow(GatewayUnavailable::class);
    });
});
