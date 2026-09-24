<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Original currency, settlement and fees (P2-37, D4).
 *
 * Three figures, kept apart on purpose: what was charged, what the provider
 * reported settling, and what the provider kept. A single figure that mixed any
 * two of them could not answer either question — what the customer paid, or
 * what the business received.
 *
 * And underneath all of it: the base ledger is in taka and version 1 performs no
 * exchange-rate accounting, so nothing here converts anything.
 */

beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    $this->account = testBusinessAccount(AccountStatus::Active);

    // A wallet opens with activation and never on demand, so the top-up test
    // needs one to exist before it can post anything.
    Wallet::query()
        ->where('business_account_id', $this->account->id)
        ->firstOr(fn () => Wallet::create([
            'business_account_id' => $this->account->id,
            'currency_code' => 'BDT',
        ]));

    $this->payment = Payment::create([
        'business_account_id' => $this->account->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => PaymentStatus::Initiated,
        'amount' => Money::fromDecimal('6000.00', Currency::BDT),
        'currency_code' => 'BDT',
        'gateway' => 'sslcommerz',
    ]);
});

/**
 * SSLCommerz's validator, optionally reporting what lands in the account.
 */
function settlementAnswer(array $overrides = []): void
{
    Http::fake(['*' => Http::response([
        'status' => 'VALID',
        'tran_id' => test()->payment->reference,
        'currency_amount' => '6000.00',
        'currency_type' => 'BDT',
        'bank_tran_id' => 'BANK-1',
        ...$overrides,
    ])]);
}

describe('what was charged is never rewritten', function () {
    it('keeps the original amount and currency exactly as they were', function () {
        settlementAnswer();

        app(SettlePayment::class)->handle($this->payment, 'val-1');

        $this->payment->refresh();

        expect($this->payment->amount->toDecimal())->toBe('6000.00')
            ->and($this->payment->currency_code)->toBe('BDT');
    });

    it('records the settlement beside the charge, not instead of it', function () {
        settlementAnswer();

        app(SettlePayment::class)->handle($this->payment, 'val-1');

        $this->payment->refresh();

        expect($this->payment->settled_amount->toDecimal())->toBe('6000.00')
            ->and($this->payment->settled_currency_code)->toBe('BDT')
            ->and($this->payment->amount->toDecimal())->toBe('6000.00');
    });
});

describe('the fee the provider kept', function () {
    it('records it separately, never netted off the amount', function () {
        /*
         * SSLCommerz documents `store_amount` as what lands in the account
         * after the bank charge, so the fee is the difference. It is recorded
         * on its own: a figure that mixed the charge and the fee could not
         * answer either question.
         */
        settlementAnswer(['store_amount' => '5850.00']);

        app(SettlePayment::class)->handle($this->payment, 'val-1');

        $this->payment->refresh();

        expect($this->payment->gateway_fee->toDecimal())->toBe('150.00')
            ->and($this->payment->gateway_fee_currency_code)->toBe('BDT')

            // Untouched. The customer still paid six thousand.
            ->and($this->payment->amount->toDecimal())->toBe('6000.00');
    });

    it('records nothing when the provider does not report one', function () {
        // A zero would claim they charged nothing.
        settlementAnswer();

        app(SettlePayment::class)->handle($this->payment, 'val-1');

        expect($this->payment->refresh()->gateway_fee)->toBeNull();
    });

    it('records nothing when the provider credits more than was charged', function () {
        // Not a fee, and not something to record as one.
        settlementAnswer(['store_amount' => '6100.00']);

        app(SettlePayment::class)->handle($this->payment, 'val-1');

        expect($this->payment->refresh()->gateway_fee)->toBeNull();
    });
});

describe('no exchange-rate accounting (D4)', function () {
    it('posts the ledger in the currency the payment was taken in', function () {
        $topUp = Payment::create([
            'business_account_id' => $this->account->id,
            'purpose' => PaymentPurpose::WalletTopUp,
            'status' => PaymentStatus::Initiated,
            'amount' => Money::fromDecimal('2500.00', Currency::BDT),
            'currency_code' => 'BDT',
            'gateway' => 'sslcommerz',
        ]);

        Http::fake(['*' => Http::response([
            'status' => 'VALID',
            'tran_id' => $topUp->reference,
            'currency_amount' => '2500.00',
            'currency_type' => 'BDT',
        ])]);

        app(SettlePayment::class)->handle($topUp, 'val-topup');

        $entry = LedgerEntry::query()->latest('id')->firstOrFail();

        expect($entry->currency_code)->toBe('BDT');
    });

    it('offers no gateway that would need a rate for a taka amount', function () {
        /*
         * The international providers decline any currency they cannot settle
         * without converting, so a taka payment is never offered a gateway that
         * would have to invent a rate — refused before initiation rather than
         * reconciled afterwards.
         */
        $offered = app(PaymentGatewayManager::class)->availableFor(Currency::BDT);

        expect($offered)->not->toContain('stripe')
            ->and($offered)->not->toContain('paypal');
    });

    it('stores no exchange rate anywhere on a payment', function () {
        /*
         * A rate column would be an invitation to start converting, and D4 is
         * explicit that version 1 does not. Asserted against the schema so
         * adding one is a deliberate act.
         */
        $columns = array_map(
            fn (object $column) => $column->column_name,
            DB::select(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = 'payments'"
            ),
        );

        $rateLike = array_values(array_filter(
            $columns,
            fn (string $column) => str_contains($column, 'exchange')
                || str_contains($column, 'conversion')
                || str_contains($column, '_rate'),
        ));

        expect($rateLike)->toBe([]);
    });
});
