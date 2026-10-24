<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Wallet\Actions\ConvertWalletBalance;
use App\Domain\Wallet\Actions\CorrectLedgerEntry;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Actions\VerifyLedgerIntegrity;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Typed wallet balances (D-new): platform credit and withdrawable money.
 *
 * One wallet, one ledger, two kinds of money in it. Platform credit is spent
 * inside Banij and never withdrawn; withdrawable money can be withdrawn and also
 * spent. Every ledger entry says which it moved, a spend takes platform credit
 * first, and the one way platform credit ever becomes withdrawable is a
 * dedicated, audited conversion.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->wallet = app(OpenWallet::class)->handle(testBusinessAccount(AccountStatus::Active));
});

function typedMoney(string $amount): Money
{
    return Money::fromDecimal($amount, Currency::BDT);
}

function typedContext(?string $key = null, array $extra = []): PostingContext
{
    return new PostingContext(...[
        'source' => 'test',
        'description' => 'Test movement',
        'idempotencyKey' => $key,
        ...$extra,
    ]);
}

/**
 * A wallet holding `$platform` of platform credit and `$withdrawable` of
 * withdrawable money, funded the only way money is allowed in.
 */
function typedBalanceFund(Wallet $wallet, string $platform, string $withdrawable): Wallet
{
    $service = app(WalletService::class);

    if (bccomp($platform, '0', 2) > 0) {
        $service->credit($wallet->refresh(), LedgerTransactionType::TopUpCredit, typedMoney($platform), typedContext());
    }

    if (bccomp($withdrawable, '0', 2) > 0) {
        $service->credit($wallet->refresh(), LedgerTransactionType::SalesCredit, typedMoney($withdrawable), typedContext());
    }

    return $wallet->refresh();
}

describe('what a credit is', function () {
    it('lands a top-up as platform credit and a sale\'s earning as withdrawable money', function () {
        $service = app(WalletService::class);

        $topUp = $service->credit($this->wallet, LedgerTransactionType::TopUpCredit, typedMoney('300.00'), typedContext());
        $sale = $service->credit($this->wallet->refresh(), LedgerTransactionType::SalesCredit, typedMoney('200.00'), typedContext());

        $entries = LedgerEntry::query()->orderBy('id')->get();

        expect($entries->pluck('balance_type')->all())->toBe([BalanceType::PlatformCredit, BalanceType::Withdrawable])
            ->and($topUp->platform_credit_amount->toDecimal())->toBe('300.00')
            ->and($sale->platform_credit_amount->toDecimal())->toBe('0.00')
            ->and($this->wallet->refresh()->total->toDecimal())->toBe('500.00')
            ->and($this->wallet->platformCredit()->toDecimal())->toBe('300.00')
            ->and($this->wallet->withdrawableHolding()->toDecimal())->toBe('200.00');
    });

    it('maps rewards to platform credit and never lets a credit nothing maps be guessed at', function () {
        $service = app(WalletService::class);

        $service->credit($this->wallet, LedgerTransactionType::PromotionalCredit, typedMoney('50.00'), typedContext());

        expect($this->wallet->refresh()->platformCredit()->toDecimal())->toBe('50.00');

        // A manual adjustment is a person's decision about whether money can
        // leave the platform: it is never defaulted.
        expect(fn () => $service->credit(
            $this->wallet->refresh(),
            LedgerTransactionType::ManualAdjustment,
            typedMoney('10.00'),
            typedContext(extra: ['reason' => 'Goodwill after a courier failure', 'actorId' => User::factory()->staff()->create()->id]),
        ))->toThrow(WalletOperationRefused::class);

        expect($this->wallet->refresh()->total->toDecimal())->toBe('50.00');
    });

    it('honours the kind a person chose for a manual credit', function () {
        $staff = User::factory()->staff()->create();

        app(CorrectLedgerEntry::class)->adjust(
            $this->wallet,
            $staff,
            typedMoney('75.00'),
            LedgerDirection::Credit,
            'Goodwill after a courier failure.',
            balanceType: BalanceType::Withdrawable,
        );

        expect($this->wallet->refresh()->withdrawableHolding()->toDecimal())->toBe('75.00')
            ->and($this->wallet->platformCredit()->toDecimal())->toBe('0.00');
    });

    it('keeps a wallet that predates typed balances entirely withdrawable', function () {
        // A wallet whose total was written before platform credit existed has
        // `platform_credit = 0`, so all of it is withdrawable, exactly as it was.
        DB::table('wallets')->where('id', $this->wallet->id)->update(['total' => '1250.00']);

        $legacy = $this->wallet->refresh();

        expect($legacy->platformCredit()->toDecimal())->toBe('0.00')
            ->and($legacy->withdrawableHolding()->toDecimal())->toBe('1250.00')
            ->and($legacy->availableForWithdrawal()->toDecimal())->toBe('1250.00')
            ->and($legacy->usableBalance()->toDecimal())->toBe('1250.00');
    });
});

describe('what a spend takes', function () {
    it('takes platform credit first and withdrawable money only for the rest, one entry for each kind', function () {
        typedBalanceFund($this->wallet, '300.00', '500.00');

        $spend = app(WalletService::class)->debit(
            $this->wallet->refresh(),
            LedgerTransactionType::PackageFeeDebit,
            typedMoney('400.00'),
            typedContext('spend:1'),
        );

        $entries = LedgerEntry::query()->where('wallet_transaction_id', $spend->id)->orderBy('id')->get();

        expect($entries)->toHaveCount(2)
            ->and($entries[0]->balance_type)->toBe(BalanceType::PlatformCredit)
            ->and($entries[0]->debit->toDecimal())->toBe('300.00')
            ->and($entries[1]->balance_type)->toBe(BalanceType::Withdrawable)
            ->and($entries[1]->debit->toDecimal())->toBe('100.00')
            // The chain of balances is whole across the two entries.
            ->and($entries[0]->balance_before->toDecimal())->toBe('800.00')
            ->and($entries[0]->balance_after->toDecimal())->toBe('500.00')
            ->and($entries[1]->balance_before->toDecimal())->toBe('500.00')
            ->and($entries[1]->balance_after->toDecimal())->toBe('400.00')
            ->and($spend->platform_credit_amount->toDecimal())->toBe('300.00');

        $wallet = $this->wallet->refresh();

        expect($wallet->total->toDecimal())->toBe('400.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('0.00')
            ->and($wallet->withdrawableHolding()->toDecimal())->toBe('400.00')
            ->and(app(VerifyLedgerIntegrity::class)->problemsFor($wallet))->toBe([]);
    });

    it('writes a single entry when one kind covers it', function () {
        typedBalanceFund($this->wallet, '300.00', '500.00');

        $spend = app(WalletService::class)->debit(
            $this->wallet->refresh(),
            LedgerTransactionType::PackageFeeDebit,
            typedMoney('120.00'),
            typedContext(),
        );

        $entries = LedgerEntry::query()->where('wallet_transaction_id', $spend->id)->get();

        expect($entries)->toHaveCount(1)
            ->and($entries->first()->balance_type)->toBe(BalanceType::PlatformCredit)
            ->and($this->wallet->refresh()->platformCredit()->toDecimal())->toBe('180.00');
    });

    it('spends once however many times the same command is sent, a mixed spend included', function () {
        typedBalanceFund($this->wallet, '300.00', '500.00');
        $service = app(WalletService::class);

        $first = $service->debit($this->wallet->refresh(), LedgerTransactionType::PackageFeeDebit, typedMoney('400.00'), typedContext('spend:once'));
        $second = $service->debit($this->wallet->refresh(), LedgerTransactionType::PackageFeeDebit, typedMoney('400.00'), typedContext('spend:once'));

        expect($second->id)->toBe($first->id)
            ->and(LedgerEntry::query()->where('wallet_transaction_id', $first->id)->count())->toBe(2)
            ->and($this->wallet->refresh()->total->toDecimal())->toBe('400.00');
    });

    it('refuses a spend the two kinds together cannot cover, and moves neither', function () {
        typedBalanceFund($this->wallet, '100.00', '100.00');

        expect(fn () => app(WalletService::class)->debit(
            $this->wallet->refresh(),
            LedgerTransactionType::PackageFeeDebit,
            typedMoney('250.00'),
            typedContext(),
        ))->toThrow(WalletOperationRefused::class);

        $wallet = $this->wallet->refresh();

        expect($wallet->total->toDecimal())->toBe('200.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('100.00');
    });

    it('spends only what is spendable, so reserved money stays out of reach of the withdrawable part', function () {
        typedBalanceFund($this->wallet, '100.00', '300.00');
        $service = app(WalletService::class);

        $service->reserve($this->wallet->refresh(), LedgerTransactionType::WithdrawalDebit, typedMoney('250.00'), typedContext('reserve'));

        // 400 held, 250 reserved: 150 is spendable, of which 100 is platform credit.
        expect(fn () => $service->debit($this->wallet->refresh(), LedgerTransactionType::PackageFeeDebit, typedMoney('160.00'), typedContext()))
            ->toThrow(WalletOperationRefused::class);

        $service->debit($this->wallet->refresh(), LedgerTransactionType::PackageFeeDebit, typedMoney('150.00'), typedContext());

        expect($this->wallet->refresh()->reserved->toDecimal())->toBe('250.00')
            ->and($this->wallet->total->toDecimal())->toBe('250.00');
    });
});

describe('what a withdrawal can claim', function () {
    it('can never claim platform credit', function () {
        typedBalanceFund($this->wallet, '1000.00', '0.00');

        $wallet = $this->wallet->refresh();

        expect($wallet->availableForWithdrawal()->toDecimal())->toBe('0.00')
            ->and($wallet->usableBalance()->toDecimal())->toBe('1000.00');

        expect(fn () => app(WalletService::class)->reserve(
            $wallet,
            LedgerTransactionType::WithdrawalDebit,
            typedMoney('100.00'),
            typedContext('withdraw:1'),
        ))->toThrow(WalletOperationRefused::class, 'withdrawable');

        expect($this->wallet->refresh()->reserved->toDecimal())->toBe('0.00');
    });

    it('claims withdrawable money, and capturing it leaves platform credit exactly where it was', function () {
        typedBalanceFund($this->wallet, '400.00', '300.00');
        $service = app(WalletService::class);

        $claim = $service->reserve($this->wallet->refresh(), LedgerTransactionType::WithdrawalDebit, typedMoney('300.00'), typedContext('withdraw:2'));

        expect($this->wallet->refresh()->availableForWithdrawal()->toDecimal())->toBe('0.00');

        $service->capture($claim);

        $entry = LedgerEntry::query()->where('wallet_transaction_id', $claim->id)->sole();
        $wallet = $this->wallet->refresh();

        expect($entry->balance_type)->toBe(BalanceType::Withdrawable)
            ->and($wallet->total->toDecimal())->toBe('400.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('400.00')
            ->and($wallet->withdrawableHolding()->toDecimal())->toBe('0.00')
            ->and(app(VerifyLedgerIntegrity::class)->problemsFor($wallet))->toBe([]);
    });

    it('refuses more than the withdrawable part holds, even when the total would cover it', function () {
        typedBalanceFund($this->wallet, '400.00', '100.00');

        expect(fn () => app(WalletService::class)->reserve(
            $this->wallet->refresh(),
            LedgerTransactionType::WithdrawalDebit,
            typedMoney('200.00'),
            typedContext('withdraw:3'),
        ))->toThrow(WalletOperationRefused::class);
    });
});

describe('what a reservation remembers', function () {
    it('takes platform credit first for a platform purchase, and remembers exactly how much of each', function () {
        typedBalanceFund($this->wallet, '100.00', '300.00');

        $claim = app(WalletService::class)->reserve(
            $this->wallet->refresh(),
            LedgerTransactionType::ServiceFeeDebit,
            typedMoney('150.00'),
            typedContext('purchase:1'),
        );

        $wallet = $this->wallet->refresh();

        expect($claim->platform_credit_amount->toDecimal())->toBe('100.00')
            ->and($wallet->reserved->toDecimal())->toBe('150.00')
            ->and($wallet->reservedPlatformCredit()->toDecimal())->toBe('100.00')
            ->and($wallet->reservedWithdrawable()->toDecimal())->toBe('50.00')
            // What is left to withdraw is only what no claim has taken.
            ->and($wallet->availableForWithdrawal()->toDecimal())->toBe('250.00')
            ->and($wallet->platformCreditAvailable()->toDecimal())->toBe('0.00')
            ->and(app(VerifyLedgerIntegrity::class)->problemsFor($wallet))->toBe([]);
    });

    it('releases exactly what it took, kind by kind, and converts nothing', function () {
        typedBalanceFund($this->wallet, '100.00', '300.00');
        $service = app(WalletService::class);

        $claim = $service->reserve($this->wallet->refresh(), LedgerTransactionType::ServiceFeeDebit, typedMoney('150.00'), typedContext('purchase:2'));

        // While it is held, the platform credit is spoken for and cannot be spent twice.
        expect(fn () => $service->debit($this->wallet->refresh(), LedgerTransactionType::PackageFeeDebit, typedMoney('260.00'), typedContext()))
            ->toThrow(WalletOperationRefused::class);

        $service->release($claim);

        $wallet = $this->wallet->refresh();

        expect($wallet->reserved->toDecimal())->toBe('0.00')
            ->and($wallet->reservedPlatformCredit()->toDecimal())->toBe('0.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('100.00')
            ->and($wallet->withdrawableHolding()->toDecimal())->toBe('300.00')
            ->and($wallet->availableForWithdrawal()->toDecimal())->toBe('300.00')
            ->and(LedgerEntry::query()->count())->toBe(2);
    });

    it('captures exactly the split it took: one entry for each kind, and the right kind falls', function () {
        typedBalanceFund($this->wallet, '100.00', '300.00');
        $service = app(WalletService::class);

        $claim = $service->reserve($this->wallet->refresh(), LedgerTransactionType::ServiceFeeDebit, typedMoney('150.00'), typedContext('purchase:3'));

        // Nothing is spent by someone else in between that could change the split.
        $service->capture($claim);

        $entries = LedgerEntry::query()->where('wallet_transaction_id', $claim->id)->orderBy('id')->get();
        $wallet = $this->wallet->refresh();

        expect($entries)->toHaveCount(2)
            ->and($entries[0]->balance_type)->toBe(BalanceType::PlatformCredit)
            ->and($entries[0]->debit->toDecimal())->toBe('100.00')
            ->and($entries[1]->balance_type)->toBe(BalanceType::Withdrawable)
            ->and($entries[1]->debit->toDecimal())->toBe('50.00')
            ->and($wallet->reserved->toDecimal())->toBe('0.00')
            ->and($wallet->reservedPlatformCredit()->toDecimal())->toBe('0.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('0.00')
            ->and($wallet->withdrawableHolding()->toDecimal())->toBe('250.00')
            ->and(app(VerifyLedgerIntegrity::class)->problemsFor($wallet))->toBe([]);
    });

    it('lets a withdrawal claim only withdrawable money even when platform credit is free', function () {
        typedBalanceFund($this->wallet, '1000.00', '200.00');

        $claim = app(WalletService::class)->reserve($this->wallet->refresh(), LedgerTransactionType::WithdrawalDebit, typedMoney('200.00'), typedContext('withdraw:typed'));

        $wallet = $this->wallet->refresh();

        expect($claim->platform_credit_amount->toDecimal())->toBe('0.00')
            ->and($wallet->reservedPlatformCredit()->toDecimal())->toBe('0.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('1000.00');

        expect(fn () => app(WalletService::class)->reserve($wallet, LedgerTransactionType::WithdrawalDebit, typedMoney('1.00'), typedContext('withdraw:typed-2')))
            ->toThrow(WalletOperationRefused::class, 'withdrawable');
    });

    it('keeps a held amount in the kind it was held from', function () {
        typedBalanceFund($this->wallet, '100.00', '300.00');

        $hold = app(WalletService::class)->hold($this->wallet->refresh(), LedgerTransactionType::ServiceFeeDebit, typedMoney('150.00'), typedContext('hold:typed'));

        $wallet = $this->wallet->refresh();

        expect($wallet->hold->toDecimal())->toBe('150.00')
            ->and($wallet->heldPlatformCredit()->toDecimal())->toBe('100.00')
            ->and($wallet->heldWithdrawable()->toDecimal())->toBe('50.00')
            ->and($hold->platform_credit_amount->toDecimal())->toBe('100.00');

        app(WalletService::class)->release($hold);

        expect($this->wallet->refresh()->heldPlatformCredit()->toDecimal())->toBe('0.00');
    });
});

describe('what can never become withdrawable', function () {
    it('never makes pending, held, reserved or required money withdrawable merely because it is not platform credit', function () {
        typedBalanceFund($this->wallet, '600.00', '400.00');

        // Untyped restrictions stand against withdrawable money first.
        DB::table('wallets')->where('id', $this->wallet->id)->update([
            'pending' => '150.00',
            'minimum_balance' => '50.00',
            'required_deposit' => '100.00',
        ]);

        $wallet = $this->wallet->refresh();

        // 400 of withdrawable money, less 150 pending, 50 minimum and 100 deposit.
        expect($wallet->availableForWithdrawal()->toDecimal())->toBe('100.00');

        // The old, total-based figure would have allowed 700: platform credit is
        // never withdrawable, so the limit is what the withdrawable part allows.
        $legacyStyle = $wallet->total->minus($wallet->reserved)->minus($wallet->hold)->minus($wallet->pending)->minus($wallet->minimum_balance)->minus($wallet->required_deposit);

        expect($legacyStyle->toDecimal())->toBe('700.00');
    });

    it('comes out exactly as before for a wallet with no platform credit', function () {
        typedBalanceFund($this->wallet, '0.00', '1000.00');

        DB::table('wallets')->where('id', $this->wallet->id)->update([
            'reserved' => '100.00',
            'hold' => '50.00',
            'pending' => '150.00',
            'minimum_balance' => '50.00',
            'required_deposit' => '100.00',
        ]);

        $wallet = $this->wallet->refresh();
        $before = $wallet->total->minus($wallet->reserved)->minus($wallet->hold)->minus($wallet->pending)->minus($wallet->minimum_balance)->minus($wallet->required_deposit);

        expect($wallet->availableForWithdrawal()->toDecimal())->toBe($before->toDecimal())
            ->and($wallet->availableForWithdrawal()->toDecimal())->toBe('550.00');
    });
});

describe('what a reversal returns', function () {
    it('puts a mixed spend back into the kinds it came from', function () {
        typedBalanceFund($this->wallet, '300.00', '500.00');

        $spend = app(WalletService::class)->debit(
            $this->wallet->refresh(),
            LedgerTransactionType::PackageFeeDebit,
            typedMoney('400.00'),
            typedContext('spend:reverse'),
        );

        $staff = User::factory()->staff()->create();

        app(CorrectLedgerEntry::class)->reverseTransaction($spend->load('ledgerEntries'), $staff, 'Charged in error.');

        $wallet = $this->wallet->refresh();

        expect($wallet->total->toDecimal())->toBe('800.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('300.00')
            ->and($wallet->withdrawableHolding()->toDecimal())->toBe('500.00')
            ->and(app(VerifyLedgerIntegrity::class)->problemsFor($wallet))->toBe([]);
    });

    it('takes a reversed credit back out of the kind it was credited as first', function () {
        typedBalanceFund($this->wallet, '300.00', '500.00');

        $topUp = LedgerEntry::query()->where('balance_type', BalanceType::PlatformCredit)->sole();

        app(CorrectLedgerEntry::class)->reverse($topUp, User::factory()->staff()->create(), 'Credited to the wrong account.');

        $wallet = $this->wallet->refresh();

        expect($wallet->platformCredit()->toDecimal())->toBe('0.00')
            ->and($wallet->withdrawableHolding()->toDecimal())->toBe('500.00');
    });

    it('refuses, and never pays out of the other kind, when the kind it was credited as has been spent', function () {
        typedBalanceFund($this->wallet, '300.00', '500.00');

        // The platform credit is spent first.
        app(WalletService::class)->debit($this->wallet->refresh(), LedgerTransactionType::PackageFeeDebit, typedMoney('300.00'), typedContext());

        $topUp = LedgerEntry::query()->where('balance_type', BalanceType::PlatformCredit)->where('credit', '>', 0)->sole();

        expect(fn () => app(CorrectLedgerEntry::class)->reverse($topUp, User::factory()->staff()->create(), 'Credited to the wrong account.'))
            ->toThrow(WalletOperationRefused::class, 'The other kind of money is not used in its place');

        // Nothing moved: the withdrawable money is exactly as it was.
        $wallet = $this->wallet->refresh();

        expect($wallet->total->toDecimal())->toBe('500.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('0.00')
            ->and($wallet->withdrawableHolding()->toDecimal())->toBe('500.00')
            ->and(app(VerifyLedgerIntegrity::class)->problemsFor($wallet))->toBe([]);
    });

    it('refuses a debit that names one kind when only the other is there', function () {
        typedBalanceFund($this->wallet, '300.00', '0.00');

        expect(fn () => app(WalletService::class)->debit(
            $this->wallet->refresh(),
            LedgerTransactionType::ManualAdjustment,
            typedMoney('100.00'),
            typedContext(extra: ['reason' => 'Taking back an overpayment.', 'actorId' => User::factory()->staff()->create()->id, 'direction' => LedgerDirection::Debit]),
            BalanceType::Withdrawable,
        ))->toThrow(WalletOperationRefused::class);

        expect($this->wallet->refresh()->total->toDecimal())->toBe('300.00');
    });
});

describe('converting platform credit to withdrawable money', function () {
    beforeEach(function () {
        $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
    });

    function typedConvert(Wallet $wallet, User $actor, string $amount, string $key = 'convert-key-1', string $reason = 'The partner earned it as a reward.'): array
    {
        return app(ConvertWalletBalance::class)->handle($wallet, $actor, typedMoney($amount), $reason, $key);
    }

    it('moves the amount from platform credit to withdrawable and leaves the total alone', function () {
        typedBalanceFund($this->wallet, '500.00', '100.00');

        $result = typedConvert($this->wallet->refresh(), $this->admin, '200.00');

        $out = LedgerEntry::query()->where('wallet_transaction_id', $result['debit']->id)->sole();
        $in = LedgerEntry::query()->where('wallet_transaction_id', $result['credit']->id)->sole();
        $wallet = $this->wallet->refresh();

        expect($wallet->total->toDecimal())->toBe('600.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('300.00')
            ->and($wallet->withdrawableHolding()->toDecimal())->toBe('300.00')
            ->and($out->type)->toBe(LedgerTransactionType::BalanceConversionDebit)
            ->and($out->balance_type)->toBe(BalanceType::PlatformCredit)
            ->and($out->debit->toDecimal())->toBe('200.00')
            ->and($in->type)->toBe(LedgerTransactionType::BalanceConversionCredit)
            ->and($in->balance_type)->toBe(BalanceType::Withdrawable)
            ->and($in->credit->toDecimal())->toBe('200.00')
            // Two halves of one decision, tied together.
            ->and($out->conversion_reference)->not->toBeNull()
            ->and($in->conversion_reference)->toBe($out->conversion_reference)
            ->and($result['debit']->conversion_reference)->toBe($out->conversion_reference)
            ->and($result['credit']->conversion_reference)->toBe($out->conversion_reference)
            // Atomic and chained: one picks up where the other leaves off.
            ->and($in->balance_before->toDecimal())->toBe($out->balance_after->toDecimal())
            ->and(app(VerifyLedgerIntegrity::class)->problemsFor($wallet))->toBe([]);
    });

    it('makes the converted amount withdrawable', function () {
        typedBalanceFund($this->wallet, '500.00', '0.00');

        expect($this->wallet->refresh()->availableForWithdrawal()->toDecimal())->toBe('0.00');

        typedConvert($this->wallet->refresh(), $this->admin, '500.00');

        expect($this->wallet->refresh()->availableForWithdrawal()->toDecimal())->toBe('500.00');

        app(WalletService::class)->reserve($this->wallet->refresh(), LedgerTransactionType::WithdrawalDebit, typedMoney('500.00'), typedContext('withdraw:after-convert'));
    });

    it('is refused when there is not enough platform credit, and writes nothing', function () {
        typedBalanceFund($this->wallet, '100.00', '900.00');

        expect(fn () => typedConvert($this->wallet->refresh(), $this->admin, '150.00'))
            ->toThrow(WalletOperationRefused::class, 'platform credit');

        expect(LedgerEntry::query()->where('type', LedgerTransactionType::BalanceConversionDebit)->count())->toBe(0)
            ->and($this->wallet->refresh()->platformCredit()->toDecimal())->toBe('100.00');
    });

    it('converts once however many times it is submitted', function () {
        typedBalanceFund($this->wallet, '500.00', '0.00');

        $first = typedConvert($this->wallet->refresh(), $this->admin, '200.00', 'same-attempt');
        $second = typedConvert($this->wallet->refresh(), $this->admin, '200.00', 'same-attempt');

        expect($second['debit']->id)->toBe($first['debit']->id)
            ->and($second['credit']->id)->toBe($first['credit']->id)
            ->and(LedgerEntry::query()->where('type', LedgerTransactionType::BalanceConversionDebit)->count())->toBe(1)
            ->and($this->wallet->refresh()->platformCredit()->toDecimal())->toBe('300.00');
    });

    it('needs a reason', function () {
        typedBalanceFund($this->wallet, '500.00', '0.00');

        expect(fn () => typedConvert($this->wallet->refresh(), $this->admin, '100.00', 'no-reason', ''))
            ->toThrow(WalletOperationRefused::class);

        expect($this->wallet->refresh()->platformCredit()->toDecimal())->toBe('500.00');
    });

    it('needs its own permission, checked in the action as well as on the route', function () {
        typedBalanceFund($this->wallet, '500.00', '0.00');

        // A Wallet Manager may adjust and reverse, but not convert.
        $manager = testPlatformStaff(PlatformRole::WalletManager);

        expect(fn () => typedConvert($this->wallet->refresh(), $manager, '100.00'))
            ->toThrow(WalletOperationRefused::class, 'not permitted');

        expect($this->wallet->refresh()->platformCredit()->toDecimal())->toBe('500.00');
    });

    it('is written to the audit log as a sensitive act with who and why', function () {
        typedBalanceFund($this->wallet, '500.00', '0.00');

        typedConvert($this->wallet->refresh(), $this->admin, '100.00');

        $audit = AuditLog::query()->where('action', 'wallet.balance_converted')->sole();

        expect($audit->actor_id)->toBe($this->admin->id)
            ->and($audit->reason)->toBe('The partner earned it as a reward.')
            ->and($audit->is_sensitive)->toBeTrue()
            ->and($audit->after['amount'])->toBe('100.00')
            ->and($audit->after['from'])->toBe('platform_credit')
            ->and($audit->after['to'])->toBe('withdrawable');
    });

    it('cannot be undone: there is no way back from withdrawable to platform credit', function () {
        expect(method_exists(WalletService::class, 'convertToWithdrawable'))->toBeTrue()
            ->and(method_exists(WalletService::class, 'convertToPlatformCredit'))->toBeFalse();
    });

    it('is reachable from the panel only with the permission, a confirmed password and a reason', function () {
        typedBalanceFund($this->wallet, '500.00', '0.00');

        $url = route('admin.wallets.conversions.store', $this->wallet->public_id);
        $body = ['amount' => '100.00', 'reason' => 'The partner earned it as a reward.', 'idempotency_key' => 'panel-attempt-1'];

        // Without a freshly confirmed password it goes to the prompt.
        $this->actingAs($this->admin)->post($url, $body)->assertRedirect(route('password.confirm'));

        // Without the permission it is refused outright.
        $this->actingAs(testPlatformStaff(PlatformRole::WalletManager))
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post($url, $body)
            ->assertForbidden();

        // Without a reason it is refused.
        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post($url, [...$body, 'reason' => ''])
            ->assertSessionHasErrors('reason');

        expect($this->wallet->refresh()->platformCredit()->toDecimal())->toBe('500.00');

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post($url, $body)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($this->wallet->refresh()->platformCredit()->toDecimal())->toBe('400.00')
            ->and($this->wallet->withdrawableHolding()->toDecimal())->toBe('100.00');
    });
});

describe('the ledger stays the authority', function () {
    it('flags platform credit that disagrees with its own entries', function () {
        typedBalanceFund($this->wallet, '300.00', '500.00');

        expect(app(VerifyLedgerIntegrity::class)->problemsFor($this->wallet->refresh()))->toBe([]);

        // Something wrote the wallet outside the posting service.
        DB::table('wallets')->where('id', $this->wallet->id)->update(['platform_credit' => '999.00']);

        $problems = app(VerifyLedgerIntegrity::class)->problemsFor($this->wallet->refresh());

        expect(collect($problems)->pluck('problem')->all())->toContain('platform_credit_mismatch');
    });

    it('refuses to relabel the kind of money an entry moved after the fact', function () {
        typedBalanceFund($this->wallet, '300.00', '0.00');

        $entry = LedgerEntry::query()->firstOrFail();

        // The ledger is append-only in the database itself: a correction is a
        // new entry, never a changed one.
        // Inside its own savepoint: a refused statement aborts the transaction
        // the test runs in, and nothing after it could be read.
        expect(fn () => DB::transaction(fn () => DB::table('ledger_entries')->where('id', $entry->id)->update(['balance_type' => 'withdrawable'])))
            ->toThrow(QueryException::class);

        expect($entry->fresh()->balance_type)->toBe(BalanceType::PlatformCredit);
    });

    it('shows both kinds on the wallet screen the account holder reads', function () {
        typedBalanceFund($this->wallet, '300.00', '500.00');

        $this->actingAs($this->wallet->businessAccount->owner)
            ->get(route('wallet.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('balances.total.amount', '800.00')
                ->where('balances.platform_credit.amount', '300.00')
                ->where('balances.withdrawable.amount', '500.00')
                ->where('balances.available_for_withdrawal.amount', '500.00'));
    });
});

describe('the database holds the line', function () {
    it('never lets one transaction record more platform credit than it moved', function () {
        $transaction = typedBalanceFund($this->wallet, '300.00', '0.00')->transactions()->firstOrFail();

        expect(fn () => DB::transaction(fn () => DB::table('wallet_transactions')->where('id', $transaction->id)->update(['platform_credit_amount' => '999.00'])))
            ->toThrow(QueryException::class);

        expect(WalletTransaction::query()->count())->toBe(1);
    });
});
