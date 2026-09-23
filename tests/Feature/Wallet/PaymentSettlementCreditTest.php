<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Actions\CreditSettledPayment;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Where a settled payment does — and does not — become wallet money (§23.1,
 * §26.3).
 *
 * Two purposes put money into a wallet. The other ten are money paid *to*
 * Feriwala, and crediting a wallet for one of those would hand back what was
 * just charged. The gateway will send the same IPN several times, so "exactly
 * once" is the other half of this.
 */

beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->wallet = app(OpenWallet::class)->handle($this->account);
});

function walletPayment(
    PaymentPurpose $purpose,
    ?BusinessAccount $account = null,
    string $amount = '6000.00',
): Payment {
    return Payment::create([
        'business_account_id' => ($account ?? test()->account)->id,
        'purpose' => $purpose,
        'status' => PaymentStatus::Initiated,
        'gateway' => 'sslcommerz',
        'amount' => Money::fromDecimal($amount),
        'currency_code' => 'BDT',
    ]);
}

function walletGatewayConfirms(Payment $payment, string $amount = '6000.00'): void
{
    Http::fake(['*' => Http::response([
        'status' => 'VALID',
        'tran_id' => $payment->reference,
        'currency_amount' => $amount,
        'currency_type' => 'BDT',
    ])]);
}

function walletSettle(Payment $payment, string $reference = 'VAL123'): void
{
    app(SettlePayment::class)->handle($payment, $reference);
}

describe('a settled top-up', function () {
    it('becomes wallet money', function () {
        $payment = walletPayment(PaymentPurpose::WalletTopUp);
        walletGatewayConfirms($payment);

        walletSettle($payment);

        expect($this->wallet->refresh()->total->toDecimal())->toBe('6000.00')
            ->and($this->wallet->usableBalance()->toDecimal())->toBe('6000.00');
    });

    it('is explained by a ledger entry naming the payment', function () {
        // A balance with no entry behind it is a figure nobody can account for.
        $payment = walletPayment(PaymentPurpose::WalletTopUp);
        walletGatewayConfirms($payment);

        walletSettle($payment);

        $entry = LedgerEntry::query()->where('wallet_id', $this->wallet->id)->firstOrFail();

        expect($entry->type)->toBe(LedgerTransactionType::TopUpCredit)
            ->and($entry->credit->toDecimal())->toBe('6000.00')
            ->and($entry->payment_id)->toBe($payment->id)
            ->and($entry->balance_before->toDecimal())->toBe('0.00')
            ->and($entry->balance_after->toDecimal())->toBe('6000.00');
    });

    it('posts a deposit as a deposit, not as a top-up', function () {
        /*
         * §24 treats the two differently, and a statement that called every
         * incoming payment a top-up could not answer "how much of this is the
         * deposit we are required to hold".
         */
        $payment = walletPayment(PaymentPurpose::WalletDeposit);
        walletGatewayConfirms($payment);

        walletSettle($payment);

        expect(LedgerEntry::query()->firstOrFail()->type)
            ->toBe(LedgerTransactionType::DepositCredit);
    });
});

describe('exactly once (§26.4)', function () {
    it('credits once however many times the IPN arrives', function () {
        // The failure this whole path exists to prevent: a retried callback
        // paying somebody twice for one payment.
        $payment = walletPayment(PaymentPurpose::WalletTopUp);
        walletGatewayConfirms($payment);

        walletSettle($payment);
        walletSettle($payment);
        walletSettle($payment);

        expect($this->wallet->refresh()->total->toDecimal())->toBe('6000.00')
            ->and(LedgerEntry::query()->where('wallet_id', $this->wallet->id)->count())->toBe(1);
    });

    it('credits once even when the crediting itself is repeated', function () {
        /*
         * The durable guard, not the cheap one. Settlement's status check and
         * lock stop the ordinary retry; this is what stops a reconciliation
         * sweep or a manual retry re-applying a payment that already landed.
         */
        $payment = walletPayment(PaymentPurpose::WalletTopUp);
        walletGatewayConfirms($payment);

        walletSettle($payment);

        $again = app(CreditSettledPayment::class)->handle($payment->fresh());

        expect($again?->reference)->toBe(
            $this->wallet->transactions()->firstOrFail()->reference
        )
            ->and($this->wallet->refresh()->total->toDecimal())->toBe('6000.00')
            ->and(LedgerEntry::query()->count())->toBe(1);
    });
});

describe('what is not wallet money', function () {
    it('does not credit a wallet for an activation payment', function () {
        /*
         * An activation fee is money paid *to* Feriwala. Turning every settled
         * payment into wallet credit would refund the fee at the moment it was
         * charged.
         */
        $payment = walletPayment(PaymentPurpose::Activation);
        walletGatewayConfirms($payment);

        walletSettle($payment);

        expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
            ->and($this->wallet->refresh()->total->toDecimal())->toBe('0.00')
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('does not credit a package renewal either', function () {
        $payment = walletPayment(PaymentPurpose::PackageRenewal);
        walletGatewayConfirms($payment);

        walletSettle($payment);

        expect($this->wallet->refresh()->total->toDecimal())->toBe('0.00');
    });

    it('ignores a top-up that has not actually settled', function () {
        // Money that has not arrived is not a balance.
        $payment = walletPayment(PaymentPurpose::WalletTopUp);

        expect(app(CreditSettledPayment::class)->handle($payment))->toBeNull()
            ->and($this->wallet->refresh()->total->toDecimal())->toBe('0.00');
    });
});

it('leaves the payment settled when the wallet cannot be credited', function () {
    /*
     * The money arrived. Rolling the settlement back because a downstream
     * posting failed would lose the record of a payment the gateway has already
     * taken — so the payment stands and an administrator is told loudly.
     */
    $other = testBusinessAccount(AccountStatus::Active);
    $payment = walletPayment(PaymentPurpose::WalletTopUp, $other);
    walletGatewayConfirms($payment);

    walletSettle($payment);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(Wallet::query()->where('business_account_id', $other->id)->exists())->toBeFalse()
        ->and($this->wallet->refresh()->total->toDecimal())->toBe('0.00');
});

it('says so loudly when there is no wallet to credit', function () {
    /*
     * Confirmed money that lands nowhere is exactly the sort of thing that goes
     * unnoticed for a fortnight, so it is a critical, not a shrug.
     */
    $payment = walletPayment(PaymentPurpose::WalletTopUp, testBusinessAccount(AccountStatus::Active));
    $payment->transitionTo(PaymentStatus::Paid);
    $payment->save();

    Log::shouldReceive('channel')->once()->with('wallet')->andReturnSelf();
    Log::shouldReceive('critical')->once();

    expect(app(CreditSettledPayment::class)->handle($payment))->toBeNull();
});
