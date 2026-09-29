<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Actions\RequestKycUpdate;
use App\Domain\Kyc\Enums\KycConsequence;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Payout\Actions\ArchivePayoutMethod;
use App\Domain\Payout\Actions\SavePayoutMethod;
use App\Domain\Payout\Enums\PayoutMethodType;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Models\PayoutMethod;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletTransactionStatus;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Domain\Withdrawal\Actions\RequestAccountWithdrawal;
use App\Domain\Withdrawal\Exceptions\AccountWithdrawalRefused;
use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/*
 * A Client/Partner `BusinessAccount`'s own withdrawal request (§27), mirroring
 * the Supplier side's own {@see \App\Domain\Supplier\Actions\RequestSupplierWithdrawal}
 * tests but exercising the generic {@see WalletService} reservation claim
 * instead of a Supplier wallet bucket.
 */

function accountWithdrawalTestWallet(string $openingCredit = '1000.00'): Wallet
{
    $wallet = app(OpenWallet::class)->handle(testBusinessAccount(AccountStatus::Active));

    $amount = Money::fromDecimal($openingCredit, Currency::BDT);

    if ($amount->isPositive()) {
        app(WalletService::class)->credit(
            $wallet,
            LedgerTransactionType::TopUpCredit,
            $amount,
            new PostingContext(source: 'test', description: 'Opening top-up'),
        );

        $wallet->refresh();
    }

    return $wallet;
}

function accountWithdrawalTestMethod(int $accountId): PayoutMethod
{
    return app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::BusinessAccount,
        ownerId: $accountId,
        type: PayoutMethodType::Bkash,
        label: 'Primary bKash',
        details: ['account_holder_name' => 'Karim Traders', 'account_number' => '01711112222'],
    );
}

it('reserves the amount and leaves the ledger untouched', function () {
    $wallet = accountWithdrawalTestWallet('1000.00');
    $account = $wallet->businessAccount;
    $method = accountWithdrawalTestMethod($account->id);

    $withdrawal = app(RequestAccountWithdrawal::class)->handle(
        $account,
        $wallet,
        $method,
        Money::fromDecimal('600.00', Currency::BDT),
        'request-test:happy-path',
    );

    $wallet->refresh();

    expect($withdrawal->status->value)->toBe('requested')
        ->and($withdrawal->amount->toDecimal())->toBe('600.00')
        ->and($withdrawal->walletTransaction->status)->toBe(WalletTransactionStatus::Pending)
        ->and($wallet->reserved->toDecimal())->toBe('600.00')
        ->and($wallet->total->toDecimal())->toBe('1000.00')
        // A reservation is a claim, not a movement (§23.1) -- no ledger entry
        // exists for it yet, only for the opening top-up.
        ->and(LedgerEntry::query()->where('wallet_id', $wallet->id)->count())->toBe(1)
        ->and($withdrawal->payout_snapshot['masked_number'])->toBe($method->maskedNumber());
});

it('is idempotent on a retried submission', function () {
    $wallet = accountWithdrawalTestWallet('1000.00');
    $account = $wallet->businessAccount;
    $method = accountWithdrawalTestMethod($account->id);

    $first = app(RequestAccountWithdrawal::class)->handle(
        $account, $wallet, $method, Money::fromDecimal('600.00', Currency::BDT), 'request-test:idempotent',
    );

    $second = app(RequestAccountWithdrawal::class)->handle(
        $account, $wallet->refresh(), $method, Money::fromDecimal('600.00', Currency::BDT), 'request-test:idempotent',
    );

    expect($second->id)->toBe($first->id)
        ->and(AccountWithdrawal::query()->count())->toBe(1)
        ->and($wallet->refresh()->reserved->toDecimal())->toBe('600.00');
});

it('refuses a request below the minimum', function () {
    $wallet = accountWithdrawalTestWallet('1000.00');
    $account = $wallet->businessAccount;
    $method = accountWithdrawalTestMethod($account->id);

    expect(fn () => app(RequestAccountWithdrawal::class)->handle(
        $account, $wallet, $method, Money::fromDecimal('10.00', Currency::BDT), 'request-test:below-minimum',
    ))->toThrow(AccountWithdrawalRefused::class);

    expect(AccountWithdrawal::query()->count())->toBe(0)
        ->and($wallet->refresh()->reserved->toDecimal())->toBe('0.00');
});

it('refuses a request above the account-level maximum override', function () {
    $wallet = accountWithdrawalTestWallet('100000.00');
    $account = $wallet->businessAccount;
    $account->forceFill(['withdrawal_maximum_override' => '5000.00'])->save();
    $method = accountWithdrawalTestMethod($account->id);

    expect(fn () => app(RequestAccountWithdrawal::class)->handle(
        $account, $wallet, $method, Money::fromDecimal('6000.00', Currency::BDT), 'request-test:above-maximum',
    ))->toThrow(AccountWithdrawalRefused::class);

    expect(AccountWithdrawal::query()->count())->toBe(0);
});

it('refuses a request an inactive payout method cannot fulfil', function () {
    $wallet = accountWithdrawalTestWallet('1000.00');
    $account = $wallet->businessAccount;
    $method = accountWithdrawalTestMethod($account->id);
    app(ArchivePayoutMethod::class)->handle($method);

    expect(fn () => app(RequestAccountWithdrawal::class)->handle(
        $account, $wallet, $method->fresh(), Money::fromDecimal('300.00', Currency::BDT), 'request-test:archived-method',
    ))->toThrow(AccountWithdrawalRefused::class);
});

it('refuses a new request while a withdrawal-blocking re-verification is open', function () {
    $wallet = accountWithdrawalTestWallet('1000.00');
    $account = $wallet->businessAccount;
    $method = accountWithdrawalTestMethod($account->id);
    $officer = User::factory()->create();

    // A re-verification asks a business that has already been through KYC
    // once (mirrors tests/Feature/Kyc/KycReverificationTest.php's fixture).
    KycSubmission::create([
        'business_account_id' => $account->id,
        'status' => KycStatus::Approved,
        'round' => 1,
        'reviewed_at' => now(),
    ]);

    app(RequestKycUpdate::class)->handle(
        $account,
        $officer,
        'Annual re-verification due.',
        'Please confirm your trade licence is current.',
        now()->addDays(14)->toImmutable(),
        null,
        [KycConsequence::BlockWithdrawals],
    );

    expect(fn () => app(RequestAccountWithdrawal::class)->handle(
        $account, $wallet, $method, Money::fromDecimal('300.00', Currency::BDT), 'request-test:kyc-blocked',
    ))->toThrow(AccountWithdrawalRefused::class);

    expect(AccountWithdrawal::query()->count())->toBe(0);
});

it('never lets a withdrawal reach into the required deposit even when the deposit may cover charges', function () {
    // §24.2: availableForWithdrawal() always excludes the deposit, whether or
    // not the deposit rule lets it cover service charges. usableBalance()
    // alone -- the generic guard WalletService::reserve() applies on its own
    // -- would not catch this, which is exactly why the domain action checks
    // the stricter figure itself under its own lock.
    $wallet = accountWithdrawalTestWallet('1000.00');
    $wallet->forceFill([
        'required_deposit' => Money::fromDecimal('400.00', Currency::BDT),
        'deposit_usable_for_charges' => true,
    ])->save();

    $account = $wallet->businessAccount;
    $method = accountWithdrawalTestMethod($account->id);

    // usableBalance() would allow 1000.00 (the deposit counts as usable for
    // charges); availableForWithdrawal() allows only 600.00.
    expect($wallet->usableBalance()->toDecimal())->toBe('1000.00')
        ->and($wallet->availableForWithdrawal()->toDecimal())->toBe('600.00');

    expect(fn () => app(RequestAccountWithdrawal::class)->handle(
        $account, $wallet, $method, Money::fromDecimal('700.00', Currency::BDT), 'request-test:deposit-protected',
    ))->toThrow(AccountWithdrawalRefused::class);

    expect(AccountWithdrawal::query()->count())->toBe(0)
        ->and($wallet->refresh()->reserved->toDecimal())->toBe('0.00');

    // Exactly at the boundary, it goes through.
    $withdrawal = app(RequestAccountWithdrawal::class)->handle(
        $account, $wallet->refresh(), $method, Money::fromDecimal('600.00', Currency::BDT), 'request-test:deposit-boundary',
    );

    expect($withdrawal->amount->toDecimal())->toBe('600.00');
});
