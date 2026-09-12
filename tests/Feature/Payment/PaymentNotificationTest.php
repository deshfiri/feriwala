<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Actions\RecordPaymentLog;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Jobs\SettleGatewayNotification;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Billing\Queries\ResolveNotifiedPayment;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Data\GatewayResult;
use App\Support\Money\Money;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Unified callback and webhook handling (P2-30, §26.4).
 *
 * One endpoint for eight providers, and three properties underneath it:
 *
 *   - Nothing here reads a provider's field names. The driver turns its own
 *     vocabulary into a result and the payment is found from that.
 *   - The endpoint answers quickly and settles on a queue, so a slow
 *     verification cannot turn one notification into five retries.
 *   - The reply says nothing about what was found, so an endpoint anybody can
 *     post to is not also a way of asking which references exist.
 */

function notificationSettings(): void
{
    $settings = app(SettingsRepository::class);

    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    // shurjoPay, whose notifications are unsigned by its own design.
    $settings->define('payment.surjopay.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.surjopay.sandbox.username', 'payment', SettingType::String, 'sp_user', isEncrypted: true);
    $settings->define('payment.surjopay.sandbox.password', 'payment', SettingType::String, 'sp_pass', isEncrypted: true);
    $settings->define('payment.surjopay.sandbox.prefix', 'payment', SettingType::String, 'FRW', isEncrypted: true);
}

/**
 * @return array<string, string>
 */
function notificationSignedIpn(string $reference, string $valId = 'val-1'): array
{
    $fields = ['tran_id' => $reference, 'val_id' => $valId, 'status' => 'VALID'];

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

function notificationPayment(string $gateway = 'sslcommerz', ?string $gatewayReference = null): Payment
{
    $account = testBusinessAccount(AccountStatus::PaymentPending);

    return Payment::create([
        'business_account_id' => $account->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => PaymentStatus::Initiated,
        'amount_minor' => 600000,
        'currency_code' => 'BDT',
        'gateway' => $gateway,
        'gateway_reference' => $gatewayReference,
    ]);
}

beforeEach(function () {
    notificationSettings();
});

describe('the endpoint answers fast and settles later', function () {
    it('queues the work instead of verifying inside the request', function () {
        /*
         * A provider that waits on us times out and sends the notification
         * again. Verification is a call to somebody else's server and
         * settlement touches a wallet, so neither happens here.
         */
        Queue::fake();

        $payment = notificationPayment();

        $this->post(route('webhooks.payment', 'sslcommerz'), notificationSignedIpn($payment->reference))
            ->assertOk();

        Queue::assertPushed(
            SettleGatewayNotification::class,
            fn (SettleGatewayNotification $job) => $job->paymentId === $payment->id
                && $job->gatewayReference === 'val-1'
                && $job->source === 'ipn',
        );

        // Nothing was decided in the request itself.
        expect($payment->refresh()->status)->toBe(PaymentStatus::Initiated);
    });

    it('records the notification before queuing anything', function () {
        Queue::fake();

        $payment = notificationPayment();

        $this->post(route('webhooks.payment', 'sslcommerz'), notificationSignedIpn($payment->reference));

        $entry = PaymentLog::query()->where('payment_id', $payment->id)->firstOrFail();

        expect($entry->direction)->toBe(PaymentLog::INBOUND)
            ->and($entry->event)->toBe('ipn')
            ->and($entry->outcome)->toBe('queued');
    });
});

describe('the reply gives nothing away', function () {
    it('answers a recognised and an unrecognised reference identically', function () {
        /*
         * An endpoint anybody can post to must not be a lookup service. If the
         * answers differed, this would tell an attacker which references exist
         * and which have been paid.
         */
        Queue::fake();

        $payment = notificationPayment();

        $known = $this->post(
            route('webhooks.payment', 'sslcommerz'),
            notificationSignedIpn($payment->reference),
        );

        $unknown = $this->post(
            route('webhooks.payment', 'sslcommerz'),
            notificationSignedIpn('PAY-DOES-NOT-EXIST'),
        );

        expect($unknown->status())->toBe($known->status())
            ->and($unknown->getContent())->toBe($known->getContent());
    });

    it('keeps a signed notification for an unknown payment on the record', function () {
        // The reply says nothing, but the row says everything: a genuine
        // notification for a payment we cannot find is worth finding later.
        Queue::fake();

        $this->post(route('webhooks.payment', 'sslcommerz'), notificationSignedIpn('PAY-DOES-NOT-EXIST'));

        expect(PaymentLog::query()->where('outcome', 'unknown_payment')->exists())->toBeTrue();

        Queue::assertNothingPushed();
    });

    it('answers a notification naming no transaction the same way too', function () {
        Queue::fake();

        $payment = notificationPayment();

        // Signed, valid, and carrying no val_id to verify with.
        $body = notificationSignedIpn($payment->reference, '');

        $this->post(route('webhooks.payment', 'sslcommerz'), $body)->assertOk();

        expect(PaymentLog::query()->where('outcome', 'no_transaction')->exists())->toBeTrue();

        Queue::assertNothingPushed();
    });
});

describe('a notification is never evidence', function () {
    it('refuses an unsigned notification from a provider that signs', function () {
        Queue::fake();

        $payment = notificationPayment();

        $this->postJson(route('webhooks.payment', 'sslcommerz'), [
            'tran_id' => $payment->reference,
            'val_id' => 'val-1',
            'status' => 'VALID',
        ])->assertStatus(401);

        Queue::assertNothingPushed();

        expect(PaymentLog::query()->where('outcome', 'refused_signature')->exists())->toBeTrue()
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Initiated);
    });

    it('accepts an unsigned one from a provider that does not sign, and still verifies', function () {
        /*
         * shurjoPay's IPN carries an order id and nothing else, and its own
         * guidance is to verify through the API on receiving one. Refusing it
         * would throw away a settlement path the provider designed.
         *
         * What makes that safe is that the notification is not evidence either
         * way — it says which payment to look at, and the job asks shurjoPay
         * what actually happened.
         */
        Queue::fake();

        $payment = notificationPayment('surjopay', 'sp-order-1');

        $this->post(route('webhooks.payment', 'surjopay'), ['order_id' => 'sp-order-1'])
            ->assertOk();

        Queue::assertPushed(
            SettleGatewayNotification::class,
            fn (SettleGatewayNotification $job) => $job->paymentId === $payment->id,
        );
    });

    it('refuses a notification for a provider that cannot verify at all', function () {
        // EPS and Nagad declare nothing, so nothing they might send means
        // anything.
        Queue::fake();

        $this->postJson(route('webhooks.payment', 'nagad'), ['payment_ref_id' => 'anything'])
            ->assertNotFound();

        Queue::assertNothingPushed();
    });

    it('refuses a gateway that does not exist', function () {
        $this->postJson(route('webhooks.payment', 'some-other-provider'), [])->assertNotFound();
    });
});

describe('finding which payment a notification is about', function () {
    it('reads the driver result rather than any provider field name', function () {
        $payment = notificationPayment();

        $found = app(ResolveNotifiedPayment::class)->handle('sslcommerz', GatewayResult::pending(
            reference: $payment->reference,
            gatewayReference: 'val-1',
        ));

        expect($found?->id)->toBe($payment->id);
    });

    it('finds a payment by the provider transaction when our reference is absent', function () {
        // bKash's callback carries a payment id and nothing else; Stripe's
        // carries a session id.
        $payment = notificationPayment('sslcommerz', 'val-known');

        $found = app(ResolveNotifiedPayment::class)->handle('sslcommerz', GatewayResult::pending(
            reference: '',
            gatewayReference: 'val-known',
        ));

        expect($found?->id)->toBe($payment->id);
    });

    it('will not let one provider name another provider\'s payment', function () {
        /*
         * A reference is only a reference for the provider that was asked to
         * take the money. Without the gateway in the question, a provider
         * anybody can sign for could settle payments routed elsewhere.
         */
        $payment = notificationPayment('sslcommerz');

        $found = app(ResolveNotifiedPayment::class)->handle('surjopay', GatewayResult::pending(
            reference: $payment->reference,
            gatewayReference: 'val-1',
        ));

        expect($found)->toBeNull();
    });

    it('finds nothing for a reference nobody has', function () {
        expect(app(ResolveNotifiedPayment::class)->handle('sslcommerz', GatewayResult::pending(
            reference: 'PAY-NOTHING',
            gatewayReference: 'val-nothing',
        )))->toBeNull();
    });
});

describe('duplicate and reordered notifications', function () {
    it('settles once however many times the same notification arrives', function () {
        $payment = notificationPayment();

        Http::fake(['*' => Http::response([
            'status' => 'VALID',
            'tran_id' => $payment->reference,
            'currency_amount' => '6000.00',
            'currency_type' => 'BDT',
            'bank_tran_id' => 'BANK-1',
        ])]);

        $body = notificationSignedIpn($payment->reference);

        $this->post(route('webhooks.payment', 'sslcommerz'), $body)->assertOk();
        $this->post(route('webhooks.payment', 'sslcommerz'), $body)->assertOk();
        $this->post(route('webhooks.payment', 'sslcommerz'), $body)->assertOk();

        $payment->refresh();

        expect($payment->status)->toBe(PaymentStatus::Paid)
            ->and($payment->gateway_settlement_reference)->toBe('BANK-1');
    });

    it('does nothing when the job runs against an already settled payment', function () {
        // The cheapest possible answer to a duplicate: one status check.
        Http::fake();

        $payment = notificationPayment();
        $payment->forceFill(['status' => PaymentStatus::Paid])->save();

        app(SettleGatewayNotification::class, [
            'paymentId' => $payment->id,
            'gatewayReference' => 'val-1',
        ])->handle(
            app(SettlePayment::class),
            app(RecordPaymentLog::class),
            app(LogManager::class),
        );

        Http::assertNothingSent();
    });

    it('does nothing when the payment has been removed', function () {
        Http::fake();

        app(SettleGatewayNotification::class, [
            'paymentId' => 999999,
            'gatewayReference' => 'val-1',
        ])->handle(
            app(SettlePayment::class),
            app(RecordPaymentLog::class),
            app(LogManager::class),
        );

        Http::assertNothingSent();
    });
});

describe('one provider transaction, one payment', function () {
    it('refuses a second payment claiming the same settlement reference', function () {
        /*
         * Several providers carry two identifiers, and it is the settlement one
         * that identifies the money. SSLCommerz can issue a fresh val_id
         * against the same bank_tran_id, so checking only the first would let
         * one real transaction pay for two orders while every reference looked
         * distinct.
         */
        $first = notificationPayment();

        Http::fake(['*' => Http::response([
            'status' => 'VALID',
            'tran_id' => $first->reference,
            'currency_amount' => '6000.00',
            'currency_type' => 'BDT',
            'bank_tran_id' => 'BANK-SHARED',
        ])]);

        app(SettlePayment::class)->handle($first, 'val-first');

        expect($first->refresh()->gateway_settlement_reference)->toBe('BANK-SHARED');

        $second = notificationPayment();

        Http::fake(['*' => Http::response([
            'status' => 'VALID',
            'tran_id' => $second->reference,
            'currency_amount' => '6000.00',
            'currency_type' => 'BDT',

            // A different val_id, the same money.
            'bank_tran_id' => 'BANK-SHARED',
        ])]);

        app(SettlePayment::class)->handle($second, 'val-second');

        expect($second->refresh()->status)->toBe(PaymentStatus::Initiated)
            ->and($second->gateway_settlement_reference)->toBeNull();
    });

    it('refuses a gateway transaction that names a different payment', function () {
        // Signature valid, transaction real, wrong order. Reported by the
        // gateway itself, which is why this is checked against its answer
        // rather than against the callback.
        $payment = notificationPayment();

        Http::fake(['*' => Http::response([
            'status' => 'VALID',
            'tran_id' => 'PAY-SOMEBODY-ELSE',
            'currency_amount' => '6000.00',
            'currency_type' => 'BDT',
        ])]);

        $result = app(SettlePayment::class)->handle($payment, 'val-1');

        expect($result->isPaid())->toBeFalse()
            ->and($result->errorCode)->toBe('reference_mismatch')
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Initiated);
    });

    it('refuses an amount the gateway reports differently', function () {
        $payment = notificationPayment();

        Http::fake(['*' => Http::response([
            'status' => 'VALID',
            'tran_id' => $payment->reference,

            // Ten taka against a six thousand taka payment.
            'currency_amount' => '10.00',
            'currency_type' => 'BDT',
        ])]);

        app(SettlePayment::class)->handle($payment, 'val-1');

        expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
            ->and($payment->amount_minor->minorUnits)->toBe(600000);
    });

    it('refuses a currency the gateway reports differently', function () {
        /*
         * A payment of ৳6,000 confirmed as $6,000 is not the same payment,
         * and Money refuses to compare across currencies — which is what makes
         * this a mismatch rather than an accidental match on the number.
         */
        $payment = notificationPayment();

        Http::fake(['*' => Http::response([
            'status' => 'VALID',
            'tran_id' => $payment->reference,
            'currency_amount' => '6000.00',
            'currency_type' => 'USD',
        ])]);

        app(SettlePayment::class)->handle($payment, 'val-1');

        expect($payment->refresh()->status)->toBe(PaymentStatus::Failed);
    });
});

describe('the browser return is never settlement evidence', function () {
    it('does not settle on a redirect the gateway never confirmed', function () {
        $account = testBusinessAccount(AccountStatus::PaymentPending);

        $payment = Payment::create([
            'business_account_id' => $account->id,
            'purpose' => PaymentPurpose::Activation,
            'status' => PaymentStatus::Initiated,
            'amount_minor' => Money::of(600000)->minorUnits,
            'currency_code' => 'BDT',
            'gateway' => 'sslcommerz',
        ]);

        // The gateway says it was never paid, whatever the browser claims.
        Http::fake(['*' => Http::response(['status' => 'FAILED', 'tran_id' => $payment->reference])]);

        $this->actingAs($account->owner)->get(route('checkout.return', [
            'tran_id' => $payment->reference,
            'val_id' => 'val-1',
            'status' => 'VALID',
        ]));

        expect($payment->refresh()->status)->not->toBe(PaymentStatus::Paid);
    });
});
