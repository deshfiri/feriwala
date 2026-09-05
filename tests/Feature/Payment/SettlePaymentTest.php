<?php

use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    $this->payment = Payment::create([
        'business_account_id' => testBusinessAccount()->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => PaymentStatus::Initiated,
        'gateway' => 'sslcommerz',
        'amount_minor' => 600000,
        'currency_code' => 'BDT',
    ]);
});

function gatewaySays(array $body): void
{
    Http::fake(['*' => Http::response($body)]);
}

function validResponse(string $amount = '6000.00'): array
{
    return [
        'status' => 'VALID',
        'tran_id' => test()->payment->reference,
        'currency_amount' => $amount,
        'currency_type' => 'BDT',
    ];
}

function settle(string $reference = 'VAL123'): mixed
{
    return app(SettlePayment::class)->handle(test()->payment, $reference);
}

describe('settling', function () {
    it('marks the payment paid after server-side verification', function () {
        gatewaySays(validResponse());

        $result = settle();

        expect($result->isPaid())->toBeTrue()
            ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Paid)
            ->and($this->payment->fresh()->completed_at)->not->toBeNull()
            ->and($this->payment->fresh()->gateway_reference)->toBe('VAL123');
    });

    it('verifies against the gateway rather than trusting the caller', function () {
        gatewaySays(validResponse());

        settle();

        // The settlement depends on this request, not on anything posted to us.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'validationserverAPI'));
    });

    it('records what the gateway actually settled', function () {
        gatewaySays(validResponse());

        settle();

        expect($this->payment->fresh()->settled_amount_minor)->toBe(600000)
            ->and($this->payment->fresh()->settled_currency_code)->toBe('BDT');
    });
});

describe('idempotency (§26.4)', function () {
    it('settles only once when the IPN arrives repeatedly', function () {
        gatewaySays(validResponse());

        settle();
        $completedAt = $this->payment->fresh()->completed_at;

        settle();
        settle();

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Paid)
            ->and($this->payment->fresh()->completed_at->timestamp)->toBe($completedAt->timestamp);
    });

    it('does not re-ask the gateway once settled', function () {
        // A repeated IPN should be cheap. Re-verifying every time would hammer
        // the gateway during a retry storm.
        gatewaySays(validResponse());

        settle();
        $afterFirst = count(Http::recorded());

        settle();

        expect(count(Http::recorded()))->toBe($afterFirst);
    });

    it('returns a paid result on replay', function () {
        gatewaySays(validResponse());

        settle();

        expect(settle()->isPaid())->toBeTrue();
    });
});

describe('amount checking', function () {
    it('refuses to settle when the gateway reports a different amount', function () {
        // Tampering or misconfiguration — either way it does not pay for this
        // order.
        gatewaySays(validResponse('10.00'));

        $result = settle();

        expect($result->isPaid())->toBeFalse()
            ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Failed)
            ->and($this->payment->fresh()->failure_reason)->toContain('different amount');
    });

    it('does not mark a mismatched payment as paid even partially', function () {
        gatewaySays(validResponse('10.00'));

        settle();

        expect($this->payment->fresh()->isSettled())->toBeFalse();
    });
});

describe('unsuccessful outcomes', function () {
    it('marks a declined payment failed', function () {
        gatewaySays(['status' => 'FAILED', 'tran_id' => $this->payment->reference]);

        settle();

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Failed);
    });

    it('leaves a pending payment pending, not failed', function () {
        // A bank transfer under review may still settle — failing it would
        // abandon money that is on its way.
        gatewaySays(['status' => 'PENDING', 'tran_id' => $this->payment->reference]);

        settle();

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Pending);
    });

    it('can settle later after being pending', function () {
        // A sequence, because a second Http::fake() does not replace the first
        // stub — the earliest match wins.
        Http::fakeSequence()
            ->push(['status' => 'PENDING', 'tran_id' => $this->payment->reference])
            ->push(validResponse());

        settle();
        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Pending);

        settle();
        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Paid);
    });
});

describe('when the gateway cannot be reached', function () {
    it('throws and leaves the payment untouched for a retry', function () {
        // The question could not be asked, so nothing may be concluded —
        // least of all that the payment failed.
        Http::fake(['*' => Http::response('', 503)]);

        expect(fn () => settle())->toThrow(GatewayUnavailable::class)
            ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Initiated)
            ->and($this->payment->fresh()->failed_at)->toBeNull();
    });

    it('settles normally once the gateway recovers', function () {
        Http::fakeSequence()
            ->pushStatus(503)
            ->push(validResponse());

        try {
            settle();
        } catch (GatewayUnavailable) {
            // expected — the outage told us nothing about the payment
        }

        settle();

        expect($this->payment->fresh()->status)->toBe(PaymentStatus::Paid);
    });
});

it('refuses to settle a cancelled payment', function () {
    $this->payment->transitionTo(PaymentStatus::Cancelled);
    $this->payment->save();

    gatewaySays(validResponse());

    expect(settle()->isPaid())->toBeFalse()
        ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Cancelled);
});
