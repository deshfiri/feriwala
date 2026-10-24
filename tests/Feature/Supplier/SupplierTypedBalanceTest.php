<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Supplier\Actions\AdjustSupplierWallet;
use App\Domain\Supplier\Actions\ConvertSupplierWalletBalance;
use App\Domain\Supplier\Actions\OpenSupplierWallet;
use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Enums\SupplierLedgerEntryType;
use App\Domain\Supplier\Exceptions\SupplierWalletOperationRefused;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierLedgerEntry;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Domain\Supplier\SupplierWalletService;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Typed balances on a Supplier's wallet (D-new).
 *
 * The same two kinds of money as an account's wallet, on the Supplier's own
 * ledger: what a Supplier earns is withdrawable, anything credited by hand says
 * which kind it is, a spend inside the platform takes platform credit first, a
 * withdrawal can only ever claim withdrawable money, and platform credit becomes
 * withdrawable only through a dedicated, audited conversion.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->supplier = Supplier::factory()->create();
    $this->wallet = app(OpenSupplierWallet::class)->handle($this->supplier, Currency::BDT);
});

function supplierTypedMoney(string $amount): Money
{
    return Money::fromDecimal($amount, Currency::BDT);
}

function supplierTypedContext(string $key, array $extra = []): SupplierPostingContext
{
    return new SupplierPostingContext(...[
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
function supplierTypedFund(SupplierWallet $wallet, string $platform, string $withdrawable): SupplierWallet
{
    $service = app(SupplierWalletService::class);
    $staff = User::factory()->staff()->create();

    if (bccomp($platform, '0', 2) > 0) {
        $service->adjust($wallet->refresh(), supplierTypedMoney($platform), supplierTypedContext('fund:platform', [
            'reason' => 'Opening platform credit', 'actorId' => $staff->id, 'direction' => LedgerDirection::Credit,
        ]), BalanceType::PlatformCredit);
    }

    if (bccomp($withdrawable, '0', 2) > 0) {
        $service->credit($wallet->refresh(), supplierTypedMoney($withdrawable), supplierTypedContext('fund:earned'));
    }

    return $wallet->refresh();
}

describe('what a credit is', function () {
    it('lands what a Supplier earns as withdrawable money', function () {
        supplierTypedFund($this->wallet, '0.00', '800.00');

        $entry = SupplierLedgerEntry::query()->sole();
        $wallet = $this->wallet->refresh();

        expect($entry->type)->toBe(SupplierLedgerEntryType::SettlementCredit)
            ->and($entry->balance_type)->toBe(BalanceType::Withdrawable)
            ->and($wallet->withdrawableHolding()->toDecimal())->toBe('800.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('0.00')
            ->and($wallet->availableForWithdrawal()->toDecimal())->toBe('800.00');
    });

    it('never defaults the kind of money for a credit by hand', function () {
        expect(fn () => app(SupplierWalletService::class)->adjust(
            $this->wallet,
            supplierTypedMoney('50.00'),
            supplierTypedContext('manual:untyped', [
                'reason' => 'Goodwill', 'actorId' => User::factory()->staff()->create()->id, 'direction' => LedgerDirection::Credit,
            ]),
        ))->toThrow(SupplierWalletOperationRefused::class, 'platform credit or withdrawable');

        expect(SupplierLedgerEntry::query()->count())->toBe(0);
    });

    it('keeps a wallet that predates typed balances entirely withdrawable', function () {
        DB::table('supplier_wallets')->where('id', $this->wallet->id)->update(['total' => '1250.00']);

        $legacy = $this->wallet->refresh();

        expect($legacy->platformCredit()->toDecimal())->toBe('0.00')
            ->and($legacy->withdrawableHolding()->toDecimal())->toBe('1250.00')
            ->and($legacy->availableForWithdrawal()->toDecimal())->toBe('1250.00')
            ->and($legacy->availableBalance()->toDecimal())->toBe('1250.00');
    });
});

describe('what a spend takes', function () {
    it('takes platform credit first, and writes one entry for each kind it used', function () {
        supplierTypedFund($this->wallet, '300.00', '500.00');

        $first = app(SupplierWalletService::class)->debitFee(
            $this->wallet->refresh(),
            supplierTypedMoney('400.00'),
            supplierTypedContext('fee:mixed'),
        );

        $entries = SupplierLedgerEntry::query()->forCommand('fee:mixed')->get();

        expect($first->id)->toBe($entries->first()->id)
            ->and($entries)->toHaveCount(2)
            ->and($entries[0]->balance_type)->toBe(BalanceType::PlatformCredit)
            ->and($entries[0]->debit->toDecimal())->toBe('300.00')
            ->and($entries[1]->balance_type)->toBe(BalanceType::Withdrawable)
            ->and($entries[1]->debit->toDecimal())->toBe('100.00')
            ->and($entries[0]->balance_after->toDecimal())->toBe($entries[1]->balance_before->toDecimal());

        $wallet = $this->wallet->refresh();

        expect($wallet->total->toDecimal())->toBe('400.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('0.00')
            ->and($wallet->withdrawableHolding()->toDecimal())->toBe('400.00');
    });

    it('spends once however many times the same command is sent, a mixed spend included', function () {
        supplierTypedFund($this->wallet, '300.00', '500.00');
        $service = app(SupplierWalletService::class);

        $first = $service->debitFee($this->wallet->refresh(), supplierTypedMoney('400.00'), supplierTypedContext('fee:once'));
        $second = $service->debitFee($this->wallet->refresh(), supplierTypedMoney('400.00'), supplierTypedContext('fee:once'));

        expect($second->id)->toBe($first->id)
            ->and(SupplierLedgerEntry::query()->forCommand('fee:once')->count())->toBe(2)
            ->and($this->wallet->refresh()->total->toDecimal())->toBe('400.00');
    });

    it('records what it could not take as recovery, on the last entry, never overdrawing', function () {
        supplierTypedFund($this->wallet, '100.00', '100.00');

        app(SupplierWalletService::class)->debitForReversal(
            $this->wallet->refresh(),
            supplierTypedMoney('350.00'),
            supplierTypedContext('reversal:short', ['reason' => 'Returned after settlement']),
        );

        $entries = SupplierLedgerEntry::query()->forCommand('reversal:short')->get();
        $wallet = $this->wallet->refresh();

        expect($entries)->toHaveCount(2)
            ->and($entries->sum(fn (SupplierLedgerEntry $entry) => (float) $entry->debit->toDecimal()))->toBe(200.0)
            ->and($entries->last()->recovery_after->toDecimal())->toBe('150.00')
            ->and($entries->first()->recovery_after->toDecimal())->toBe('0.00')
            ->and($wallet->total->toDecimal())->toBe('0.00')
            ->and($wallet->recovery->toDecimal())->toBe('150.00');
    });
});

describe('what a withdrawal can claim', function () {
    it('can never claim platform credit', function () {
        supplierTypedFund($this->wallet, '1000.00', '0.00');

        $wallet = $this->wallet->refresh();

        expect($wallet->availableForWithdrawal()->toDecimal())->toBe('0.00')
            ->and($wallet->availableBalance()->toDecimal())->toBe('1000.00');

        expect(fn () => app(SupplierWalletService::class)->reserve(
            $wallet,
            supplierTypedMoney('100.00'),
            supplierTypedContext('withdraw:platform-only'),
        ))->toThrow(SupplierWalletOperationRefused::class);

        expect($this->wallet->refresh()->reserved->toDecimal())->toBe('0.00');
    });

    it('claims withdrawable money, and paying it leaves platform credit where it was', function () {
        supplierTypedFund($this->wallet, '400.00', '300.00');
        $service = app(SupplierWalletService::class);

        $service->reserve($this->wallet->refresh(), supplierTypedMoney('300.00'), supplierTypedContext('withdraw:reserve'));

        expect($this->wallet->refresh()->availableForWithdrawal()->toDecimal())->toBe('0.00');

        $service->pay($this->wallet->refresh(), supplierTypedMoney('300.00'), supplierTypedContext('withdraw:pay'));

        $wallet = $this->wallet->refresh();

        expect($wallet->total->toDecimal())->toBe('400.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('400.00')
            ->and($wallet->withdrawableHolding()->toDecimal())->toBe('0.00')
            ->and($wallet->reserved->toDecimal())->toBe('0.00');
    });
});

describe('what a reservation remembers', function () {
    it('takes one kind, remembers it on the entry, and gives back exactly that kind', function () {
        supplierTypedFund($this->wallet, '300.00', '500.00');
        $service = app(SupplierWalletService::class);

        $entry = $service->reserve($this->wallet->refresh(), supplierTypedMoney('200.00'), supplierTypedContext('purchase:s1'), BalanceType::PlatformCredit);

        $wallet = $this->wallet->refresh();

        expect($entry->balance_type)->toBe(BalanceType::PlatformCredit)
            ->and($wallet->reserved->toDecimal())->toBe('200.00')
            ->and($wallet->reservedPlatformCredit()->toDecimal())->toBe('200.00')
            ->and($wallet->platformCreditAvailable()->toDecimal())->toBe('100.00')
            // The withdrawable money is untouched by a platform purchase.
            ->and($wallet->availableForWithdrawal()->toDecimal())->toBe('500.00');

        $service->release($wallet, supplierTypedMoney('200.00'), supplierTypedContext('purchase:s1:release', ['reason' => 'Order cancelled']), BalanceType::PlatformCredit);

        $wallet = $this->wallet->refresh();

        expect($wallet->reserved->toDecimal())->toBe('0.00')
            ->and($wallet->reservedPlatformCredit()->toDecimal())->toBe('0.00')
            ->and($wallet->platformCreditAvailable()->toDecimal())->toBe('300.00')
            ->and($wallet->availableForWithdrawal()->toDecimal())->toBe('500.00');
    });

    it('pays a platform reservation out of platform credit and leaves withdrawable money alone', function () {
        supplierTypedFund($this->wallet, '300.00', '500.00');
        $service = app(SupplierWalletService::class);

        $service->reserve($this->wallet->refresh(), supplierTypedMoney('200.00'), supplierTypedContext('purchase:s2'), BalanceType::PlatformCredit);
        $paid = $service->pay($this->wallet->refresh(), supplierTypedMoney('200.00'), supplierTypedContext('purchase:s2:pay'), BalanceType::PlatformCredit);

        $wallet = $this->wallet->refresh();

        expect($paid->balance_type)->toBe(BalanceType::PlatformCredit)
            ->and($wallet->total->toDecimal())->toBe('600.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('100.00')
            ->and($wallet->withdrawableHolding()->toDecimal())->toBe('500.00')
            ->and($wallet->reserved->toDecimal())->toBe('0.00')
            ->and($wallet->reservedPlatformCredit()->toDecimal())->toBe('0.00');
    });

    it('never lets a platform claim reach for withdrawable money, or the reverse', function () {
        supplierTypedFund($this->wallet, '100.00', '500.00');
        $service = app(SupplierWalletService::class);

        expect(fn () => $service->reserve($this->wallet->refresh(), supplierTypedMoney('150.00'), supplierTypedContext('purchase:s3'), BalanceType::PlatformCredit))
            ->toThrow(SupplierWalletOperationRefused::class);

        expect(fn () => $service->reserve($this->wallet->refresh(), supplierTypedMoney('600.00'), supplierTypedContext('withdraw:s3')))
            ->toThrow(SupplierWalletOperationRefused::class);

        expect($this->wallet->refresh()->reserved->toDecimal())->toBe('0.00');
    });

    it('stands the outstanding recovery against withdrawable money, never against platform credit', function () {
        supplierTypedFund($this->wallet, '300.00', '200.00');

        DB::table('supplier_wallets')->where('id', $this->wallet->id)->update(['recovery' => '50.00']);

        $wallet = $this->wallet->refresh();

        expect($wallet->availableForWithdrawal()->toDecimal())->toBe('150.00');
    });
});

describe('converting platform credit to withdrawable money', function () {
    beforeEach(function () {
        $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
    });

    function supplierTypedConvert(SupplierWallet $wallet, User $actor, string $amount, string $key = 'convert-1', string $reason = 'The Supplier earned it as a reward.'): array
    {
        return app(ConvertSupplierWalletBalance::class)->handle($wallet, $actor, supplierTypedMoney($amount), $reason, $key);
    }

    it('moves the amount from platform credit to withdrawable and leaves the total alone', function () {
        supplierTypedFund($this->wallet, '500.00', '100.00');

        $result = supplierTypedConvert($this->wallet->refresh(), $this->admin, '200.00');
        $wallet = $this->wallet->refresh();

        expect($wallet->total->toDecimal())->toBe('600.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('300.00')
            ->and($wallet->withdrawableHolding()->toDecimal())->toBe('300.00')
            ->and($result['debit']->type)->toBe(SupplierLedgerEntryType::BalanceConversionDebit)
            ->and($result['debit']->balance_type)->toBe(BalanceType::PlatformCredit)
            ->and($result['credit']->type)->toBe(SupplierLedgerEntryType::BalanceConversionCredit)
            ->and($result['credit']->balance_type)->toBe(BalanceType::Withdrawable)
            ->and($result['debit']->conversion_reference)->not->toBeNull()
            ->and($result['credit']->conversion_reference)->toBe($result['debit']->conversion_reference)
            ->and($result['credit']->balance_before->toDecimal())->toBe($result['debit']->balance_after->toDecimal());
    });

    it('is refused when there is not enough platform credit, and writes nothing', function () {
        supplierTypedFund($this->wallet, '100.00', '900.00');

        expect(fn () => supplierTypedConvert($this->wallet->refresh(), $this->admin, '150.00'))
            ->toThrow(SupplierWalletOperationRefused::class, 'platform credit');

        expect(SupplierLedgerEntry::query()->where('type', SupplierLedgerEntryType::BalanceConversionDebit)->count())->toBe(0);
    });

    it('converts once however many times it is submitted', function () {
        supplierTypedFund($this->wallet, '500.00', '0.00');

        $first = supplierTypedConvert($this->wallet->refresh(), $this->admin, '200.00', 'same-attempt');
        $second = supplierTypedConvert($this->wallet->refresh(), $this->admin, '200.00', 'same-attempt');

        expect($second['debit']->id)->toBe($first['debit']->id)
            ->and(SupplierLedgerEntry::query()->where('type', SupplierLedgerEntryType::BalanceConversionDebit)->count())->toBe(1)
            ->and($this->wallet->refresh()->platformCredit()->toDecimal())->toBe('300.00');
    });

    it('needs a reason and its own permission, and is audited', function () {
        supplierTypedFund($this->wallet, '500.00', '0.00');

        expect(fn () => supplierTypedConvert($this->wallet->refresh(), $this->admin, '100.00', 'no-reason', ''))
            ->toThrow(SupplierWalletOperationRefused::class);

        expect(fn () => supplierTypedConvert($this->wallet->refresh(), testPlatformStaff(PlatformRole::WalletManager), '100.00', 'no-permission'))
            ->toThrow(SupplierWalletOperationRefused::class, 'not permitted');

        supplierTypedConvert($this->wallet->refresh(), $this->admin, '100.00', 'with-both');

        $audit = AuditLog::query()->where('action', 'supplier_wallet.balance_converted')->sole();

        expect($audit->actor_id)->toBe($this->admin->id)
            ->and($audit->is_sensitive)->toBeTrue()
            ->and($audit->after['amount'])->toBe('100.00');
    });

    it('makes the converted amount withdrawable', function () {
        supplierTypedFund($this->wallet, '500.00', '0.00');

        supplierTypedConvert($this->wallet->refresh(), $this->admin, '500.00');

        expect($this->wallet->refresh()->availableForWithdrawal()->toDecimal())->toBe('500.00');
    });
});

describe('a manual top-up', function () {
    beforeEach(function () {
        $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
    });

    it('needs the kind of money, a reason, the permission, and records them all', function () {
        $action = app(AdjustSupplierWallet::class);

        $entry = $action->handle(
            $this->wallet,
            $this->admin,
            supplierTypedMoney('250.00'),
            LedgerDirection::Credit,
            'Goodwill after a courier failure.',
            'topup-1',
            BalanceType::PlatformCredit,
        );

        $audit = AuditLog::query()->where('action', 'supplier_wallet.manual_adjustment')->sole();

        expect($entry->balance_type)->toBe(BalanceType::PlatformCredit)
            ->and($this->wallet->refresh()->platformCredit()->toDecimal())->toBe('250.00')
            ->and($audit->actor_id)->toBe($this->admin->id)
            ->and($audit->reason)->toBe('Goodwill after a courier failure.')
            ->and($audit->after['balance_type'])->toBe('platform_credit')
            ->and($audit->is_sensitive)->toBeTrue();

        expect(fn () => $action->handle($this->wallet->refresh(), $this->admin, supplierTypedMoney('10.00'), LedgerDirection::Credit, 'Goodwill after a courier failure.', 'topup-2'))
            ->toThrow(SupplierWalletOperationRefused::class, 'platform credit or withdrawable');

        expect(fn () => $action->handle($this->wallet->refresh(), testPlatformStaff(PlatformRole::PaymentManager), supplierTypedMoney('10.00'), LedgerDirection::Credit, 'Goodwill after a courier failure.', 'topup-3', BalanceType::Withdrawable))
            ->toThrow(SupplierWalletOperationRefused::class, 'not permitted');
    });

    it('posts once however many times it is submitted', function () {
        $action = app(AdjustSupplierWallet::class);

        foreach ([1, 2] as $attempt) {
            $action->handle($this->wallet->refresh(), $this->admin, supplierTypedMoney('250.00'), LedgerDirection::Credit, 'Goodwill after a courier failure.', 'same-topup', BalanceType::Withdrawable);
        }

        expect($this->wallet->refresh()->total->toDecimal())->toBe('250.00')
            ->and(SupplierLedgerEntry::query()->count())->toBe(1);
    });

    it('is reachable from the panel only with the permission and a confirmed password', function () {
        $url = route('admin.supplier-wallets.adjustments.store', $this->wallet->public_id);
        $body = [
            'amount' => '250.00', 'direction' => 'credit', 'balance_type' => 'withdrawable',
            'reason' => 'Goodwill after a courier failure.', 'idempotency_key' => 'panel-topup-1',
        ];

        $this->actingAs($this->admin)->post($url, $body)->assertRedirect(route('password.confirm'));

        $this->actingAs(testPlatformStaff(PlatformRole::PaymentManager))
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post($url, $body)
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post($url, [...$body, 'balance_type' => ''])
            ->assertSessionHasErrors('balance_type');

        expect(SupplierLedgerEntry::query()->count())->toBe(0);

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post($url, $body)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($this->wallet->refresh()->withdrawableHolding()->toDecimal())->toBe('250.00');
    });
});

describe('the database holds the line', function () {
    it('refuses to relabel the kind of money an entry moved', function () {
        supplierTypedFund($this->wallet, '300.00', '0.00');

        $entry = SupplierLedgerEntry::query()->firstOrFail();

        expect(fn () => DB::transaction(fn () => DB::table('supplier_ledger_entries')->where('id', $entry->id)->update(['balance_type' => 'withdrawable'])))
            ->toThrow(QueryException::class);

        expect($entry->fresh()->balance_type)->toBe(BalanceType::PlatformCredit);
    });

    it('refuses platform credit below zero', function () {
        expect(fn () => DB::transaction(fn () => DB::table('supplier_wallets')->where('id', $this->wallet->id)->update(['platform_credit' => '-1.00'])))
            ->toThrow(QueryException::class);
    });
});
