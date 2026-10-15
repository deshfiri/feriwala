<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Actions\QuoteGatewayCharge;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\ExchangeRates;
use App\Domain\Billing\Models\Payment;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Integrations\Payment\Data\PaymentIntent;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\Gateways\PayPal\PayPalGateway;
use App\Integrations\Payment\Gateways\Stripe\StripeGateway;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Taking a Taka payment through an international gateway (D4, amended).
 *
 * The Taka amount is the obligation and stays the ledger's figure. What the
 * gateway is asked for is the equivalent at an administrator's rate, rounded up,
 * stamped on the payment before the call and checked when the gateway answers.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $settings = app(SettingsRepository::class);
    $settings->define('payment.stripe.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.stripe.sandbox.secret_key', 'payment', SettingType::String, 'sk_test_abc123', isEncrypted: true);
    $settings->define('payment.stripe.sandbox.webhook_secret', 'payment', SettingType::String, 'whsec_x', isEncrypted: true);
    $settings->define('payment.stripe.enabled', 'payment', SettingType::Boolean, true);
    $settings->set('payment.stripe.enabled', true);
});

/** Set the stored rates directly, as the admin screen would leave them. */
function fxRates(array $rates): void
{
    $settings = app(SettingsRepository::class);
    $settings->define(ExchangeRates::SETTING, 'payment', SettingType::Json, []);
    $settings->set(ExchangeRates::SETTING, $rates);
}

function fxPayment(string $amount = '1000.00', PaymentPurpose $purpose = PaymentPurpose::Activation): Payment
{
    $account = testBusinessAccount(AccountStatus::Active);

    Wallet::query()->firstOrCreate(
        ['business_account_id' => $account->id],
        ['currency_code' => 'BDT'],
    );

    return Payment::create([
        'business_account_id' => $account->id,
        'purpose' => $purpose,
        'status' => PaymentStatus::Initiated,
        'amount' => Money::fromDecimal($amount, Currency::BDT),
        'currency_code' => 'BDT',
        'gateway' => 'stripe',
    ]);
}

describe('the conversion', function () {
    it('rounds the charge up to the cent, so the platform is never short', function () {
        $rates = app(ExchangeRates::class);

        // 1000 / 120 = 8.3333… → 8.34, never 8.33.
        expect($rates->chargeFor(Money::fromDecimal('1000', Currency::BDT), Currency::USD, '120')->toDecimal())->toBe('8.34')
            // Exact quotients are left alone.
            ->and($rates->chargeFor(Money::fromDecimal('1200', Currency::BDT), Currency::USD, '120')->toDecimal())->toBe('10.00')
            ->and($rates->chargeFor(Money::fromDecimal('1000', Currency::BDT), Currency::EUR, '131.25')->toDecimal())->toBe('7.62');
    });

    it('rounds a refunded share down, so partial refunds can never exceed the charge', function () {
        $rates = app(ExchangeRates::class);

        $charge = $rates->chargeFor(Money::fromDecimal('1000', Currency::BDT), Currency::USD, '120');
        $parts = array_map(
            fn (string $part) => $rates->shareOf(Money::fromDecimal($part, Currency::BDT), Currency::USD, '120'),
            ['333.33', '333.33', '333.34'],
        );

        $sum = array_reduce($parts, fn (Money $carry, Money $part) => $carry->plus($part), Money::zero(Currency::USD));

        expect($rates->shareOf(Money::fromDecimal('500', Currency::BDT), Currency::USD, '120')->toDecimal())->toBe('4.16')
            ->and($sum->lessThanOrEqualTo($charge))->toBeTrue();
    });

    it('refuses a rate that is not a plain positive number, and amounts that are not Taka', function () {
        $rates = app(ExchangeRates::class);
        $taka = Money::fromDecimal('100', Currency::BDT);

        foreach (['0', '-5', 'abc', '1,20', '1.1234567', ''] as $bad) {
            expect(ExchangeRates::isValidRate($bad))->toBeFalse();
        }

        expect(fn () => $rates->chargeFor($taka, Currency::USD, '0'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $rates->chargeFor(Money::fromDecimal('1', Currency::USD), Currency::EUR, '1.1'))->toThrow(InvalidArgumentException::class);
    });
});

describe('what a Taka payer is offered', function () {
    it('does not offer an international gateway until a rate is set', function () {
        expect(app(PaymentGatewayManager::class)->availableFor(Currency::BDT))->not->toContain('stripe');

        fxRates(['USD' => '120.50']);

        expect(app(PaymentGatewayManager::class)->availableFor(Currency::BDT))->toContain('stripe')
            // And a foreign payer is unaffected: it takes USD natively.
            ->and(app(PaymentGatewayManager::class)->availableFor(Currency::USD))->toContain('stripe');
    });

    it('takes a rate away again by blanking it', function () {
        fxRates(['USD' => '120.50']);
        fxRates([]);

        expect(app(PaymentGatewayManager::class)->availableFor(Currency::BDT))->not->toContain('stripe');
    });

    it('never converts one foreign currency into another', function () {
        fxRates(['USD' => '120.50']);

        expect(app(PaymentGatewayManager::class)->chargeCurrencyFor('stripe', Currency::USD))->toBe(Currency::USD)
            ->and(app(PaymentGatewayManager::class)->chargeCurrencyFor('sslcommerz', Currency::USD))->toBeNull();
    });

    it('tells the payer the currency, the rate and the figure before they choose', function () {
        fxRates(['USD' => '120']);

        $notice = app(PaymentGatewayManager::class)->conversionNotice('stripe', Currency::BDT, Money::fromDecimal('1000', Currency::BDT));

        expect($notice['currency'])->toBe('USD')
            ->and($notice['rate'])->toBe('120')
            ->and($notice['charge']['amount'] ?? null)->toBe('8.34')
            // A Taka gateway has nothing to disclose.
            ->and(app(PaymentGatewayManager::class)->conversionNotice('sslcommerz', Currency::BDT))->toBeNull();
    });
});

describe('quoting a charge', function () {
    it('stamps the figure and the rate on the payment and leaves the Taka alone', function () {
        fxRates(['USD' => '120']);
        $payment = fxPayment('1000.00');

        $charge = app(QuoteGatewayCharge::class)->handle($payment);
        $payment->refresh();

        expect($charge->toDecimal())->toBe('8.34')
            ->and($charge->currency)->toBe(Currency::USD)
            ->and($payment->charge_amount->toDecimal())->toBe('8.34')
            ->and($payment->charge_currency_code)->toBe('USD')
            ->and(rtrim(rtrim((string) $payment->fx_rate, '0'), '.'))->toBe('120')
            ->and($payment->fx_quoted_at)->not->toBeNull()
            ->and($payment->amount->toDecimal())->toBe('1000.00')
            ->and($payment->currency_code)->toBe('BDT')
            ->and($payment->expectedGatewayAmount()->toDecimal())->toBe('8.34');
    });

    it('keeps the first quote when the rate moves, so a retry is charged the same', function () {
        fxRates(['USD' => '120']);
        $payment = fxPayment('1000.00');

        app(QuoteGatewayCharge::class)->handle($payment);

        fxRates(['USD' => '150']);

        expect(app(QuoteGatewayCharge::class)->handle($payment->refresh())->toDecimal())->toBe('8.34');
    });

    it('sends a Taka gateway the Taka amount and clears an earlier quote', function () {
        fxRates(['USD' => '120']);
        $payment = fxPayment('1000.00');

        app(QuoteGatewayCharge::class)->handle($payment);
        $payment->forceFill(['gateway' => 'sslcommerz'])->save();

        $charge = app(QuoteGatewayCharge::class)->handle($payment->refresh());

        expect($charge->toDecimal())->toBe('1000.00')
            ->and($charge->currency)->toBe(Currency::BDT)
            ->and($payment->refresh()->charge_amount)->toBeNull()
            ->and($payment->fx_rate)->toBeNull();
    });

    it('refuses to quote when no rate is set, rather than inventing one', function () {
        $payment = fxPayment('1000.00');

        expect(fn () => app(QuoteGatewayCharge::class)->handle($payment))->toThrow(GatewayUnavailable::class);
        expect($payment->refresh()->charge_amount)->toBeNull();
    });
});

describe('settling in the charge currency', function () {
    beforeEach(function () {
        fxRates(['USD' => '120']);
    });

    function fxStripePaid(Payment $payment, string $cents, string $currency = 'usd'): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'cs_fx_1',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => (int) $cents,
            'currency' => $currency,
            'client_reference_id' => $payment->reference,
            'payment_intent' => 'pi_fx_1',
        ])]);
    }

    it('settles when the gateway reports the quoted figure, keeping the Taka as the ledger amount', function () {
        $payment = fxPayment('1000.00', PaymentPurpose::WalletTopUp);
        app(QuoteGatewayCharge::class)->handle($payment);

        fxStripePaid($payment, '834');

        app(SettlePayment::class)->handle($payment->refresh(), 'cs_fx_1');
        $payment->refresh();

        expect($payment->status)->toBe(PaymentStatus::Paid)
            // What was charged is untouched…
            ->and($payment->amount->toDecimal())->toBe('1000.00')
            ->and($payment->currency_code)->toBe('BDT')
            // …and the provider's settlement sits beside it, in its own currency.
            ->and($payment->settled_amount->toDecimal())->toBe('8.34')
            ->and($payment->settled_currency_code)->toBe('USD');

        // The wallet is credited the Taka owed, not the dollars received.
        $entry = LedgerEntry::query()->latest('id')->firstOrFail();

        expect($entry->currency_code)->toBe('BDT')
            ->and($entry->credit->toDecimal())->toBe('1000.00');
    });

    it('does not settle when the gateway reports a different figure', function () {
        $payment = fxPayment('1000.00');
        app(QuoteGatewayCharge::class)->handle($payment);

        // 8.33 is one cent short of the quote: not payment for this order.
        fxStripePaid($payment, '833');

        $result = app(SettlePayment::class)->handle($payment->refresh(), 'cs_fx_1');

        expect($result->isPaid())->toBeFalse()
            ->and($payment->refresh()->status)->not->toBe(PaymentStatus::Paid);
    });

    it('does not settle when the gateway reports Taka-sized dollars', function () {
        $payment = fxPayment('1000.00');
        app(QuoteGatewayCharge::class)->handle($payment);

        // The face value of the Taka amount, in the wrong currency.
        fxStripePaid($payment, '100000');

        $result = app(SettlePayment::class)->handle($payment->refresh(), 'cs_fx_1');

        expect($result->isPaid())->toBeFalse()
            ->and($payment->refresh()->status)->not->toBe(PaymentStatus::Paid);
    });
});

describe('refunds go back in what was charged', function () {
    it('returns the whole charge for a whole refund, and a rounded-down share for a part', function () {
        fxRates(['USD' => '120']);
        $payment = fxPayment('1000.00');
        app(QuoteGatewayCharge::class)->handle($payment);
        $payment->refresh();

        // A later rate change does not reach a payment already taken.
        fxRates(['USD' => '200']);

        expect($payment->gatewayShareOf(Money::fromDecimal('1000.00', Currency::BDT))->toDecimal())->toBe('8.34')
            ->and($payment->gatewayShareOf(Money::fromDecimal('500.00', Currency::BDT))->toDecimal())->toBe('4.16')
            ->and($payment->gatewayShareOf(Money::fromDecimal('500.00', Currency::BDT))->currency)->toBe(Currency::USD);
    });

    it('is the Taka figure for a payment that was never converted', function () {
        $payment = fxPayment('1000.00');
        $payment->forceFill(['gateway' => 'sslcommerz'])->save();

        expect($payment->gatewayShareOf(Money::fromDecimal('250.00', Currency::BDT))->toDecimal())->toBe('250.00')
            ->and($payment->expectedGatewayAmount()->toDecimal())->toBe('1000.00');
    });
});

describe('the administrator screen', function () {
    beforeEach(function () {
        $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
    });

    it('saves rates, and makes the gateway available for Taka', function () {
        $this->actingAs($this->admin, 'web')
            ->put(route('admin.gateways.exchange-rates.update'), ['rates' => ['USD' => '120.50', 'EUR' => '', 'GBP' => '']])
            ->assertSessionHasNoErrors();

        expect(app(ExchangeRates::class)->all())->toBe(['USD' => '120.50'])
            ->and(app(PaymentGatewayManager::class)->availableFor(Currency::BDT))->toContain('stripe');
    });

    it('records the change in the audit trail with the old and new rates', function () {
        fxRates(['USD' => '118']);

        $this->actingAs($this->admin, 'web')
            ->put(route('admin.gateways.exchange-rates.update'), ['rates' => ['USD' => '121', 'EUR' => '', 'GBP' => '']]);

        $entry = AuditLog::query()->where('action', 'payment.exchange_rates_updated')->latest('id')->firstOrFail();

        expect($entry->before['rates'])->toBe(['USD' => '118'])
            ->and($entry->after['rates'])->toBe(['USD' => '121']);
    });

    it('rejects a rate that is not usable and stores nothing', function () {
        foreach (['0', '-1', 'ten', '1.1234567'] as $bad) {
            $this->actingAs($this->admin, 'web')
                ->put(route('admin.gateways.exchange-rates.update'), ['rates' => ['USD' => $bad, 'EUR' => '', 'GBP' => '']])
                ->assertSessionHasErrors('rates.USD');
        }

        expect(app(ExchangeRates::class)->all())->toBe([]);
    });

    it('is turned away for someone who may not manage the gateways', function () {
        $viewer = testPlatformStaff(PlatformRole::ContentManager);

        $this->actingAs($viewer, 'web')
            ->put(route('admin.gateways.exchange-rates.update'), ['rates' => ['USD' => '120']])
            ->assertForbidden();

        expect(app(ExchangeRates::class)->all())->toBe([]);
    });

    it('shows the saved rates, the figure they work out to, and what they unlock', function () {
        fxRates(['USD' => '120']);

        $this->actingAs($this->admin, 'web')->get(route('admin.gateways.exchange-rates'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/exchange-rates')
                ->where('rates.0.currency', 'USD')
                ->where('rates.0.rate', '120')
                ->where('rates.0.sample.amount', '8.34')
                ->where('rates.1.rate', null)
                ->where('gateways', fn ($gateways) => collect($gateways)->firstWhere('name', 'stripe')['offered_for_taka'] === true));
    });
});

describe('a provider that cannot be reached', function () {
    it('is reported as unavailable rather than as a server error', function () {
        // Stripe and PayPal became reachable by a Taka payer with the rates, so
        // their outage must read as "gateway unavailable", never a 500.
        Http::fake(fn () => throw new ConnectionException('cURL error 7'));

        $settings = app(SettingsRepository::class);
        $settings->define('payment.paypal.mode', 'payment', SettingType::String, 'sandbox');
        $settings->define('payment.paypal.sandbox.client_id', 'payment', SettingType::String, 'id', isEncrypted: true);
        $settings->define('payment.paypal.sandbox.client_secret', 'payment', SettingType::String, 'secret', isEncrypted: true);
        $settings->define('payment.paypal.sandbox.webhook_id', 'payment', SettingType::String, 'wh', isEncrypted: true);

        $intent = fn () => new PaymentIntent(
            reference: 'PAY-OUTAGE-1',
            amount: Money::fromDecimal('8.34', Currency::USD),
            customerName: 'Nusrat',
            customerEmail: 'n@example.test',
            customerMobile: null,
            successUrl: 'https://erp.test/s',
            failUrl: 'https://erp.test/f',
            cancelUrl: 'https://erp.test/c',
            ipnUrl: 'https://erp.test/i',
            description: 'Top-up',
        );

        expect(fn () => app(StripeGateway::class)->initiate($intent()))->toThrow(GatewayUnavailable::class)
            ->and(fn () => app(PayPalGateway::class)->initiate($intent()))->toThrow(GatewayUnavailable::class);
    });
});
