<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletTransactionStatus;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/*
 * The posting service (P2-5, §23, §36.1).
 *
 * Every balance change goes through here and every one writes its immutable
 * entry in the same transaction as the balance it explains. A figure nobody can
 * account for is exactly what a ledger exists to make impossible.
 */

function serviceWallet(int $openingCredit = 0): Wallet
{
    $wallet = app(OpenWallet::class)->handle(testBusinessAccount(AccountStatus::Active));

    if ($openingCredit > 0) {
        // Funded the only way money is allowed in — through the service.
        app(WalletService::class)->credit(
            $wallet,
            LedgerTransactionType::TopUpCredit,
            Money::of($openingCredit, Currency::BDT),
            new PostingContext(source: 'test', description: 'Opening top-up'),
        );

        $wallet->refresh();
    }

    return $wallet;
}

/**
 * @param  array<string, mixed>  $extra
 */
function serviceContext(string $description = 'Test movement', array $extra = []): PostingContext
{
    return new PostingContext(...array_merge([
        'source' => 'test',
        'description' => $description,
    ], $extra));
}

describe('crediting and debiting', function () {
    it('moves the balance and explains it in the same breath', function () {
        $wallet = serviceWallet();

        $transaction = app(WalletService::class)->credit(
            $wallet,
            LedgerTransactionType::TopUpCredit,
            Money::of(50000, Currency::BDT),
            serviceContext('Wallet top-up'),
        );

        $entry = LedgerEntry::query()->firstOrFail();

        expect($wallet->refresh()->total_minor->minorUnits)->toBe(50000)
            ->and($transaction->status)->toBe(WalletTransactionStatus::Settled)
            ->and($entry->wallet_transaction_id)->toBe($transaction->id)
            ->and($entry->credit_minor->minorUnits)->toBe(50000)
            ->and($entry->debit_minor->minorUnits)->toBe(0)
            ->and($entry->balance_before_minor->minorUnits)->toBe(0)
            ->and($entry->balance_after_minor->minorUnits)->toBe(50000)
            ->and($entry->balances())->toBeTrue();
    });

    it('takes money out and records where the balance ended', function () {
        $wallet = serviceWallet(50000);

        app(WalletService::class)->debit(
            $wallet,
            LedgerTransactionType::PackageFeeDebit,
            Money::of(20000, Currency::BDT),
            serviceContext('Package fee'),
        );

        $entry = LedgerEntry::query()->latest('id')->firstOrFail();

        expect($wallet->refresh()->total_minor->minorUnits)->toBe(30000)
            ->and($entry->debit_minor->minorUnits)->toBe(20000)
            ->and($entry->balance_before_minor->minorUnits)->toBe(50000)
            ->and($entry->balance_after_minor->minorUnits)->toBe(30000);
    });

    it('records the bucket snapshot §23.2 asks for', function () {
        // So a statement from last March reads without reconstructing March.
        $wallet = serviceWallet(100000);

        app(WalletService::class)->reserve(
            $wallet,
            LedgerTransactionType::ServiceFeeDebit,
            Money::of(30000, Currency::BDT),
            serviceContext('Reserved for a service fee'),
        );

        app(WalletService::class)->credit(
            $wallet->refresh(),
            LedgerTransactionType::SalesCredit,
            Money::of(10000, Currency::BDT),
            serviceContext('Sales earnings'),
        );

        $entry = LedgerEntry::query()->latest('id')->firstOrFail();

        expect($entry->reserved_minor->minorUnits)->toBe(30000)
            ->and($entry->available_minor->minorUnits)->toBe(80000);
    });

    it('refuses to spend money that is not there', function () {
        // No P2.A requirement authorises a negative available balance, and an
        // overdraft nobody agreed to is a loan nobody agreed to.
        $wallet = serviceWallet(10000);

        expect(fn () => app(WalletService::class)->debit(
            $wallet,
            LedgerTransactionType::PlatformFeeDebit,
            Money::of(25000, Currency::BDT),
            serviceContext(),
        ))->toThrow(WalletOperationRefused::class);

        expect($wallet->refresh()->total_minor->minorUnits)->toBe(10000)
            ->and(LedgerEntry::query()->count())->toBe(1);
    });

    it('refuses to spend money that is reserved', function () {
        // Reserved money is already spoken for. That is the point of it.
        $wallet = serviceWallet(50000);

        app(WalletService::class)->reserve(
            $wallet,
            LedgerTransactionType::ServiceFeeDebit,
            Money::of(40000, Currency::BDT),
            serviceContext('Reserved'),
        );

        expect(fn () => app(WalletService::class)->debit(
            $wallet->refresh(),
            LedgerTransactionType::PlatformFeeDebit,
            Money::of(20000, Currency::BDT),
            serviceContext(),
        ))->toThrow(WalletOperationRefused::class);
    });

    it('refuses a currency the wallet does not hold', function () {
        /*
         * D4 keeps the schema multi-currency-ready and gives v1 no
         * exchange-rate accounting, so converting here would be a rate nobody
         * agreed applied to somebody's money.
         */
        $wallet = serviceWallet();

        expect(fn () => app(WalletService::class)->credit(
            $wallet,
            LedgerTransactionType::TopUpCredit,
            Money::of(50000, Currency::USD),
            serviceContext(),
        ))->toThrow(WalletOperationRefused::class);

        expect(LedgerEntry::query()->count())->toBe(0);
    });

    it('refuses a movement of nothing', function () {
        expect(fn () => app(WalletService::class)->credit(
            serviceWallet(),
            LedgerTransactionType::TopUpCredit,
            Money::of(0, Currency::BDT),
            serviceContext(),
        ))->toThrow(WalletOperationRefused::class);
    });

    it('leaves nothing behind when it refuses', function () {
        // A failed command must not leave a half-posted transaction or an
        // orphan claim.
        $wallet = serviceWallet(1000);

        try {
            app(WalletService::class)->debit(
                $wallet,
                LedgerTransactionType::WithdrawalDebit,
                Money::of(9999999, Currency::BDT),
                serviceContext(),
            );
        } catch (WalletOperationRefused) {
            // expected
        }

        expect(WalletTransaction::query()->count())->toBe(1)
            ->and(LedgerEntry::query()->count())->toBe(1)
            ->and($wallet->refresh()->total_minor->minorUnits)->toBe(1000);
    });
});

describe('idempotency', function () {
    it('posts once however many times the same command arrives', function () {
        /*
         * The invariant everything else rests on. A gateway retries, a user
         * double-clicks, a queue redelivers — and the wallet must end in the
         * same place as if it had happened once.
         */
        $wallet = serviceWallet();
        $service = app(WalletService::class);

        $context = serviceContext('Top-up', ['idempotencyKey' => 'topup:PAY-1']);

        $first = $service->credit($wallet, LedgerTransactionType::TopUpCredit, Money::of(50000, Currency::BDT), $context);
        $second = $service->credit($wallet->refresh(), LedgerTransactionType::TopUpCredit, Money::of(50000, Currency::BDT), $context);

        expect($second->id)->toBe($first->id)
            ->and($wallet->refresh()->total_minor->minorUnits)->toBe(50000)
            ->and(WalletTransaction::query()->count())->toBe(1)
            ->and(LedgerEntry::query()->count())->toBe(1);
    });

    it('treats two different commands as two', function () {
        $wallet = serviceWallet();
        $service = app(WalletService::class);

        foreach (['topup:PAY-1', 'topup:PAY-2'] as $key) {
            $service->credit(
                $wallet->refresh(),
                LedgerTransactionType::TopUpCredit,
                Money::of(10000, Currency::BDT),
                serviceContext('Top-up', ['idempotencyKey' => $key]),
            );
        }

        expect($wallet->refresh()->total_minor->minorUnits)->toBe(20000)
            ->and(LedgerEntry::query()->count())->toBe(2);
    });
});

describe('holding, reserving and letting go', function () {
    it('moves money out of reach without moving it out of the wallet', function () {
        // Nothing arrives or leaves, so no entry is written — what changes is
        // what can be spent.
        $wallet = serviceWallet(100000);

        app(WalletService::class)->hold(
            $wallet,
            LedgerTransactionType::ServiceFeeDebit,
            Money::of(40000, Currency::BDT),
            serviceContext('Held pending review'),
        );

        $wallet->refresh();

        expect($wallet->total_minor->minorUnits)->toBe(100000)
            ->and($wallet->hold_minor->minorUnits)->toBe(40000)
            ->and($wallet->usableBalance()->minorUnits)->toBe(60000)
            // One entry: the opening top-up. The hold moved no value.
            ->and(LedgerEntry::query()->count())->toBe(1);
    });

    it('gives a claim back exactly once', function () {
        $wallet = serviceWallet(100000);
        $service = app(WalletService::class);

        $claim = $service->hold(
            $wallet,
            LedgerTransactionType::ServiceFeeDebit,
            Money::of(40000, Currency::BDT),
            serviceContext('Held'),
        );

        $service->release($claim);

        expect($wallet->refresh()->hold_minor->minorUnits)->toBe(0)
            ->and($wallet->usableBalance()->minorUnits)->toBe(100000);

        // The second attempt has no move left, so it is refused rather than
        // releasing the same money twice.
        expect(fn () => $service->release($claim->refresh()))
            ->toThrow(WalletOperationRefused::class);

        expect($wallet->refresh()->hold_minor->minorUnits)->toBe(0);
    });

    it('turns a claim into a real debit exactly once', function () {
        $wallet = serviceWallet(100000);
        $service = app(WalletService::class);

        $claim = $service->reserve(
            $wallet,
            LedgerTransactionType::ServiceFeeDebit,
            Money::of(40000, Currency::BDT),
            serviceContext('Reserved for a service fee'),
        );

        $service->capture($claim);

        $wallet->refresh();
        $entry = LedgerEntry::query()->latest('id')->firstOrFail();

        expect($wallet->total_minor->minorUnits)->toBe(60000)
            ->and($wallet->reserved_minor->minorUnits)->toBe(0)
            ->and($entry->debit_minor->minorUnits)->toBe(40000)
            ->and($entry->balance_before_minor->minorUnits)->toBe(100000)
            ->and($entry->balance_after_minor->minorUnits)->toBe(60000);

        expect(fn () => $service->capture($claim->refresh()))
            ->toThrow(WalletOperationRefused::class);

        expect($wallet->refresh()->total_minor->minorUnits)->toBe(60000);
    });

    it('refuses to claim money the wallet does not have', function () {
        $wallet = serviceWallet(10000);

        expect(fn () => app(WalletService::class)->reserve(
            $wallet,
            LedgerTransactionType::ServiceFeeDebit,
            Money::of(50000, Currency::BDT),
            serviceContext(),
        ))->toThrow(WalletOperationRefused::class);

        expect($wallet->refresh()->reserved_minor->minorUnits)->toBe(0);
    });
});

describe('what a correction has to carry', function () {
    it('refuses a manual adjustment with no reason', function () {
        // A figure that changed for no recorded reason is the first thing an
        // auditor asks about.
        expect(fn () => app(WalletService::class)->credit(
            serviceWallet(),
            LedgerTransactionType::ManualAdjustment,
            Money::of(1000, Currency::BDT),
            serviceContext('Adjustment', ['direction' => LedgerDirection::Credit, 'actorId' => User::factory()->staff()->create()->id]),
        ))->toThrow(WalletOperationRefused::class);
    });

    it('refuses a manual adjustment with nobody behind it', function () {
        expect(fn () => app(WalletService::class)->credit(
            serviceWallet(),
            LedgerTransactionType::ManualAdjustment,
            Money::of(1000, Currency::BDT),
            serviceContext('Adjustment', ['reason' => 'Goodwill', 'direction' => LedgerDirection::Credit]),
        ))->toThrow(WalletOperationRefused::class);
    });

    it('accepts one that carries both', function () {
        $staff = User::factory()->staff()->create();

        $transaction = app(WalletService::class)->credit(
            serviceWallet(),
            LedgerTransactionType::ManualAdjustment,
            Money::of(1000, Currency::BDT),
            serviceContext('Adjustment', [
                'reason' => 'Goodwill after a courier failure',
                'actorId' => $staff->id,
                'direction' => LedgerDirection::Credit,
            ]),
        );

        expect($transaction->reason)->toBe('Goodwill after a courier failure')
            ->and($transaction->created_by)->toBe($staff->id);
    });
});

describe('account isolation', function () {
    it('never lets one account\'s posting touch another\'s wallet', function () {
        $mine = serviceWallet(50000);
        $theirs = serviceWallet(50000);

        app(WalletService::class)->debit(
            $mine,
            LedgerTransactionType::PlatformFeeDebit,
            Money::of(20000, Currency::BDT),
            serviceContext(),
        );

        expect($mine->refresh()->total_minor->minorUnits)->toBe(30000)
            ->and($theirs->refresh()->total_minor->minorUnits)->toBe(50000)
            ->and(LedgerEntry::query()->where('wallet_id', $theirs->id)->count())->toBe(1);
    });
});
