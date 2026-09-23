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

function serviceWallet(string $openingCredit = '0.00'): Wallet
{
    $wallet = app(OpenWallet::class)->handle(testBusinessAccount(AccountStatus::Active));

    $amount = Money::fromDecimal($openingCredit, Currency::BDT);

    if ($amount->isPositive()) {
        // Funded the only way money is allowed in — through the service.
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
            Money::fromDecimal('500.00', Currency::BDT),
            serviceContext('Wallet top-up'),
        );

        $entry = LedgerEntry::query()->firstOrFail();

        expect($wallet->refresh()->total->toDecimal())->toBe('500.00')
            ->and($transaction->status)->toBe(WalletTransactionStatus::Settled)
            ->and($entry->wallet_transaction_id)->toBe($transaction->id)
            ->and($entry->credit->toDecimal())->toBe('500.00')
            ->and($entry->debit->toDecimal())->toBe('0.00')
            ->and($entry->balance_before->toDecimal())->toBe('0.00')
            ->and($entry->balance_after->toDecimal())->toBe('500.00')
            ->and($entry->balances())->toBeTrue();
    });

    it('takes money out and records where the balance ended', function () {
        $wallet = serviceWallet('500.00');

        app(WalletService::class)->debit(
            $wallet,
            LedgerTransactionType::PackageFeeDebit,
            Money::fromDecimal('200.00', Currency::BDT),
            serviceContext('Package fee'),
        );

        $entry = LedgerEntry::query()->latest('id')->firstOrFail();

        expect($wallet->refresh()->total->toDecimal())->toBe('300.00')
            ->and($entry->debit->toDecimal())->toBe('200.00')
            ->and($entry->balance_before->toDecimal())->toBe('500.00')
            ->and($entry->balance_after->toDecimal())->toBe('300.00');
    });

    it('records the bucket snapshot §23.2 asks for', function () {
        // So a statement from last March reads without reconstructing March.
        $wallet = serviceWallet('1000.00');

        app(WalletService::class)->reserve(
            $wallet,
            LedgerTransactionType::ServiceFeeDebit,
            Money::fromDecimal('300.00', Currency::BDT),
            serviceContext('Reserved for a service fee'),
        );

        app(WalletService::class)->credit(
            $wallet->refresh(),
            LedgerTransactionType::SalesCredit,
            Money::fromDecimal('100.00', Currency::BDT),
            serviceContext('Sales earnings'),
        );

        $entry = LedgerEntry::query()->latest('id')->firstOrFail();

        expect($entry->reserved->toDecimal())->toBe('300.00')
            ->and($entry->available->toDecimal())->toBe('800.00');
    });

    it('refuses to spend money that is not there', function () {
        // No P2.A requirement authorises a negative available balance, and an
        // overdraft nobody agreed to is a loan nobody agreed to.
        $wallet = serviceWallet('100.00');

        expect(fn () => app(WalletService::class)->debit(
            $wallet,
            LedgerTransactionType::PlatformFeeDebit,
            Money::fromDecimal('250.00', Currency::BDT),
            serviceContext(),
        ))->toThrow(WalletOperationRefused::class);

        expect($wallet->refresh()->total->toDecimal())->toBe('100.00')
            ->and(LedgerEntry::query()->count())->toBe(1);
    });

    it('refuses to spend money that is reserved', function () {
        // Reserved money is already spoken for. That is the point of it.
        $wallet = serviceWallet('500.00');

        app(WalletService::class)->reserve(
            $wallet,
            LedgerTransactionType::ServiceFeeDebit,
            Money::fromDecimal('400.00', Currency::BDT),
            serviceContext('Reserved'),
        );

        expect(fn () => app(WalletService::class)->debit(
            $wallet->refresh(),
            LedgerTransactionType::PlatformFeeDebit,
            Money::fromDecimal('200.00', Currency::BDT),
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
            Money::fromDecimal('500.00', Currency::USD),
            serviceContext(),
        ))->toThrow(WalletOperationRefused::class);

        expect(LedgerEntry::query()->count())->toBe(0);
    });

    it('refuses a movement of nothing', function () {
        expect(fn () => app(WalletService::class)->credit(
            serviceWallet(),
            LedgerTransactionType::TopUpCredit,
            Money::fromDecimal('0.00', Currency::BDT),
            serviceContext(),
        ))->toThrow(WalletOperationRefused::class);
    });

    it('leaves nothing behind when it refuses', function () {
        // A failed command must not leave a half-posted transaction or an
        // orphan claim.
        $wallet = serviceWallet('10.00');

        try {
            app(WalletService::class)->debit(
                $wallet,
                LedgerTransactionType::WithdrawalDebit,
                Money::fromDecimal('99999.99', Currency::BDT),
                serviceContext(),
            );
        } catch (WalletOperationRefused) {
            // expected
        }

        expect(WalletTransaction::query()->count())->toBe(1)
            ->and(LedgerEntry::query()->count())->toBe(1)
            ->and($wallet->refresh()->total->toDecimal())->toBe('10.00');
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

        $first = $service->credit($wallet, LedgerTransactionType::TopUpCredit, Money::fromDecimal('500.00', Currency::BDT), $context);
        $second = $service->credit($wallet->refresh(), LedgerTransactionType::TopUpCredit, Money::fromDecimal('500.00', Currency::BDT), $context);

        expect($second->id)->toBe($first->id)
            ->and($wallet->refresh()->total->toDecimal())->toBe('500.00')
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
                Money::fromDecimal('100.00', Currency::BDT),
                serviceContext('Top-up', ['idempotencyKey' => $key]),
            );
        }

        expect($wallet->refresh()->total->toDecimal())->toBe('200.00')
            ->and(LedgerEntry::query()->count())->toBe(2);
    });
});

describe('holding, reserving and letting go', function () {
    it('moves money out of reach without moving it out of the wallet', function () {
        // Nothing arrives or leaves, so no entry is written — what changes is
        // what can be spent.
        $wallet = serviceWallet('1000.00');

        app(WalletService::class)->hold(
            $wallet,
            LedgerTransactionType::ServiceFeeDebit,
            Money::fromDecimal('400.00', Currency::BDT),
            serviceContext('Held pending review'),
        );

        $wallet->refresh();

        expect($wallet->total->toDecimal())->toBe('1000.00')
            ->and($wallet->hold->toDecimal())->toBe('400.00')
            ->and($wallet->usableBalance()->toDecimal())->toBe('600.00')
            // One entry: the opening top-up. The hold moved no value.
            ->and(LedgerEntry::query()->count())->toBe(1);
    });

    it('gives a claim back exactly once', function () {
        $wallet = serviceWallet('1000.00');
        $service = app(WalletService::class);

        $claim = $service->hold(
            $wallet,
            LedgerTransactionType::ServiceFeeDebit,
            Money::fromDecimal('400.00', Currency::BDT),
            serviceContext('Held'),
        );

        $service->release($claim);

        expect($wallet->refresh()->hold->toDecimal())->toBe('0.00')
            ->and($wallet->usableBalance()->toDecimal())->toBe('1000.00');

        // The second attempt has no move left, so it is refused rather than
        // releasing the same money twice.
        expect(fn () => $service->release($claim->refresh()))
            ->toThrow(WalletOperationRefused::class);

        expect($wallet->refresh()->hold->toDecimal())->toBe('0.00');
    });

    it('turns a claim into a real debit exactly once', function () {
        $wallet = serviceWallet('1000.00');
        $service = app(WalletService::class);

        $claim = $service->reserve(
            $wallet,
            LedgerTransactionType::ServiceFeeDebit,
            Money::fromDecimal('400.00', Currency::BDT),
            serviceContext('Reserved for a service fee'),
        );

        $service->capture($claim);

        $wallet->refresh();
        $entry = LedgerEntry::query()->latest('id')->firstOrFail();

        expect($wallet->total->toDecimal())->toBe('600.00')
            ->and($wallet->reserved->toDecimal())->toBe('0.00')
            ->and($entry->debit->toDecimal())->toBe('400.00')
            ->and($entry->balance_before->toDecimal())->toBe('1000.00')
            ->and($entry->balance_after->toDecimal())->toBe('600.00');

        expect(fn () => $service->capture($claim->refresh()))
            ->toThrow(WalletOperationRefused::class);

        expect($wallet->refresh()->total->toDecimal())->toBe('600.00');
    });

    it('refuses to claim money the wallet does not have', function () {
        $wallet = serviceWallet('100.00');

        expect(fn () => app(WalletService::class)->reserve(
            $wallet,
            LedgerTransactionType::ServiceFeeDebit,
            Money::fromDecimal('500.00', Currency::BDT),
            serviceContext(),
        ))->toThrow(WalletOperationRefused::class);

        expect($wallet->refresh()->reserved->toDecimal())->toBe('0.00');
    });
});

describe('what a correction has to carry', function () {
    it('refuses a manual adjustment with no reason', function () {
        // A figure that changed for no recorded reason is the first thing an
        // auditor asks about.
        expect(fn () => app(WalletService::class)->credit(
            serviceWallet(),
            LedgerTransactionType::ManualAdjustment,
            Money::fromDecimal('10.00', Currency::BDT),
            serviceContext('Adjustment', ['direction' => LedgerDirection::Credit, 'actorId' => User::factory()->staff()->create()->id]),
        ))->toThrow(WalletOperationRefused::class);
    });

    it('refuses a manual adjustment with nobody behind it', function () {
        expect(fn () => app(WalletService::class)->credit(
            serviceWallet(),
            LedgerTransactionType::ManualAdjustment,
            Money::fromDecimal('10.00', Currency::BDT),
            serviceContext('Adjustment', ['reason' => 'Goodwill', 'direction' => LedgerDirection::Credit]),
        ))->toThrow(WalletOperationRefused::class);
    });

    it('accepts one that carries both', function () {
        $staff = User::factory()->staff()->create();

        $transaction = app(WalletService::class)->credit(
            serviceWallet(),
            LedgerTransactionType::ManualAdjustment,
            Money::fromDecimal('10.00', Currency::BDT),
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
        $mine = serviceWallet('500.00');
        $theirs = serviceWallet('500.00');

        app(WalletService::class)->debit(
            $mine,
            LedgerTransactionType::PlatformFeeDebit,
            Money::fromDecimal('200.00', Currency::BDT),
            serviceContext(),
        );

        expect($mine->refresh()->total->toDecimal())->toBe('300.00')
            ->and($theirs->refresh()->total->toDecimal())->toBe('500.00')
            ->and(LedgerEntry::query()->where('wallet_id', $theirs->id)->count())->toBe(1);
    });
});
