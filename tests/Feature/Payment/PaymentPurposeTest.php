<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentLog;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Support\Facades\Http;

/*
 * Every payment purpose §26.3 names, wired (P2-33).
 *
 * Twelve purposes, and the platform is being built in phases — so for a while
 * some are real purposes with nothing on the other side yet. What matters is
 * that each one is answered explicitly: a purpose that fell silently into
 * "nothing" would be money arriving for something nobody wired up, which is the
 * failure that goes unnoticed for a month.
 */

beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    $this->account = testBusinessAccount(AccountStatus::Active);

    // A wallet opens with activation and never on demand, so the tests that
    // credit one need it to exist first.
    $this->wallet = Wallet::query()
        ->where('business_account_id', $this->account->id)
        ->firstOr(fn () => Wallet::create([
            'business_account_id' => $this->account->id,
            'currency_code' => 'BDT',
        ]));
});

function purposePayment(PaymentPurpose $purpose, int $amountMinor = 600000): Payment
{
    return Payment::create([
        'business_account_id' => test()->account->id,
        'purpose' => $purpose,
        'status' => PaymentStatus::Initiated,
        'amount_minor' => $amountMinor,
        'currency_code' => 'BDT',
        'gateway' => 'sslcommerz',
    ]);
}

function settlePurpose(Payment $payment): void
{
    Http::fake(['*' => Http::response([
        'status' => 'VALID',
        'tran_id' => $payment->reference,
        'currency_amount' => $payment->amount_minor->toDecimal(),
        'currency_type' => 'BDT',
    ])]);

    app(SettlePayment::class)->handle($payment, 'val-'.$payment->id);
}

it('names all twelve purposes §26.3 lists', function () {
    expect(PaymentPurpose::cases())->toHaveCount(12);
});

it('says which purposes have a module behind them and which do not', function () {
    /*
     * Said out loud rather than inferred. A settled payment for an unbuilt
     * module is a real thing that has to be visible, not an accident that looks
     * like a payment which did nothing wrong.
     */
    $deliverable = array_values(array_filter(
        PaymentPurpose::cases(),
        fn (PaymentPurpose $purpose) => $purpose->isDeliverable(),
    ));

    expect($deliverable)->toBe([
        PaymentPurpose::Activation,
        PaymentPurpose::PackageRenewal,
        PaymentPurpose::PackageUpgrade,
        PaymentPurpose::PackageDowngrade,
        PaymentPurpose::WalletDeposit,
        PaymentPurpose::WalletTopUp,
    ]);
});

it('credits a wallet for exactly two of them', function () {
    // Everything else is money paid *to* Feriwala, and crediting a wallet for
    // one of those would hand back what was just charged (§23.1).
    $crediting = array_values(array_filter(
        PaymentPurpose::cases(),
        fn (PaymentPurpose $purpose) => $purpose->creditsWallet(),
    ));

    expect($crediting)->toBe([PaymentPurpose::WalletDeposit, PaymentPurpose::WalletTopUp]);
});

describe('settling each purpose', function () {
    it('credits the wallet for a top-up', function () {
        $wallet = $this->wallet;
        $before = $wallet->refresh()->total_minor->minorUnits;

        settlePurpose(purposePayment(PaymentPurpose::WalletTopUp, 250000));

        expect($wallet->refresh()->total_minor->minorUnits)->toBe($before + 250000);
    });

    it('credits the wallet for a deposit', function () {
        $wallet = $this->wallet;
        $before = $wallet->refresh()->total_minor->minorUnits;

        settlePurpose(purposePayment(PaymentPurpose::WalletDeposit, 300000));

        expect($wallet->refresh()->total_minor->minorUnits)->toBe($before + 300000);
    });

    it('does not credit a wallet for an activation fee', function () {
        $wallet = $this->wallet;
        $before = $wallet->refresh()->total_minor->minorUnits;

        settlePurpose(purposePayment(PaymentPurpose::Activation));

        expect($wallet->refresh()->total_minor->minorUnits)->toBe($before);
    });

    it('settles a purpose whose module is not built, and says so', function () {
        /*
         * The money is real and is recorded as such — nothing rolled back, no
         * status invented. What happens is that it stops being invisible.
         */
        $payment = purposePayment(PaymentPurpose::WholesaleOrder);

        settlePurpose($payment);

        expect($payment->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and(PaymentLog::query()->where('outcome', 'undeliverable_purpose')->exists())
            ->toBeTrue();
    });

    it('records the purpose on the entry that flags it', function () {
        $payment = purposePayment(PaymentPurpose::DomainCharge);

        settlePurpose($payment);

        $entry = PaymentLog::query()->where('outcome', 'undeliverable_purpose')->firstOrFail();

        expect($entry->context['purpose'])->toBe('domain_charge')
            ->and($entry->payment_id)->toBe($payment->id);
    });

    it('does not flag a purpose that has a module', function () {
        settlePurpose(purposePayment(PaymentPurpose::Activation));

        expect(PaymentLog::query()->where('outcome', 'undeliverable_purpose')->exists())
            ->toBeFalse();
    });
});
