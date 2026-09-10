<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Wallet\Actions\CorrectLedgerEntry;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/*
 * The administration side of a wallet (P2-8, §23.2, §32).
 *
 * Three things are being held to account here: that an administrator sees the
 * same money the account holder does, that the two operations which move it by
 * hand are gated and recorded, and that neither of them ever edits anything.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->wallet = app(OpenWallet::class)->handle($this->account);
    $this->manager = testPlatformStaff(PlatformRole::WalletManager);
});

function adminCredit(Wallet $wallet, int $amount, string $description = 'Top-up'): WalletTransaction
{
    return app(WalletService::class)->credit(
        $wallet->refresh(),
        LedgerTransactionType::TopUpCredit,
        Money::of($amount, Currency::BDT),
        new PostingContext(source: 'test', description: $description),
    );
}

/**
 * A signed-in administrator whose password was confirmed a moment ago.
 *
 * The money-moving routes ask for it, so a test that is not about that control
 * has to satisfy it — otherwise every one of them quietly becomes a test of the
 * password prompt.
 */
function adminConfirmed(User $actor): TestCase
{
    return test()
        ->actingAs($actor)
        ->withSession(['auth.password_confirmed_at' => time()]);
}

describe('the lookup', function () {
    it('lists wallets to somebody who may read them', function () {
        adminCredit($this->wallet, 50000);

        $this->actingAs($this->manager)
            ->get(route('admin.wallets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/wallets/index')
                ->has('wallets.data', 1)
                ->where('wallets.data.0.account', $this->account->name)
                ->where('wallets.data.0.total.minor_units', 50000));
    });

    it('refuses somebody without the permission', function () {
        // Reconciling what a gateway sent is a different job from reading what
        // a business holds, so `payment.view` is not enough.
        $this->actingAs(testPlatformStaff(PlatformRole::PaymentManager))
            ->get(route('admin.wallets.index'))
            ->assertForbidden();
    });

    it('refuses a business user outright', function () {
        $this->actingAs($this->account->owner)
            ->get(route('admin.wallets.index'))
            ->assertForbidden();
    });
});

describe('one wallet', function () {
    it('shows the same balances the account holder sees', function () {
        adminCredit($this->wallet, 50000);

        $this->actingAs($this->manager)
            ->get(route('admin.wallets.show', $this->wallet->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/wallets/show')
                ->where('balances.total.minor_units', 50000)
                ->where('balances.usable.minor_units', 50000)
                ->has('transactions.data', 1)
                ->where('can.adjust', true)
                ->where('can.reverse', true));
    });

    it('offers no adjustment control to somebody who may only read', function () {
        // Absent rather than disabled: a button that only ever refuses teaches
        // nobody anything.
        $this->seed(RolesAndPermissionsSeeder::class);

        $reader = User::factory()->staff()->withTwoFactor()->create();
        $reader->givePermissionTo('wallet.view');

        $this->actingAs($reader)
            ->get(route('admin.wallets.show', $this->wallet->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.adjust', false)
                ->where('can.reverse', false));
    });
});

describe('the staff note (§23.2)', function () {
    it('reaches an administrator who may read sensitive data', function () {
        adminCredit($this->wallet, 100000);

        $transaction = app(CorrectLedgerEntry::class)->adjust(
            $this->wallet->refresh(),
            $this->manager,
            Money::of(2500, Currency::BDT),
            LedgerDirection::Credit,
            'Goodwill after a courier failure.',
            'Customer complained loudly on social media.',
        );

        $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
            ->get(route('admin.wallets.transactions.show', [
                $this->wallet->public_id,
                $transaction->public_id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.view_sensitive', true)
                ->where('transaction.internal_note', 'Customer complained loudly on social media.'));
    });

    it('is withheld from an administrator who may not', function () {
        /*
         * The Wallet Manager holds `ledger.view` but not
         * `ledger.view_sensitive_data`. The note is not sent at all rather than
         * sent and hidden by the browser.
         */
        adminCredit($this->wallet, 100000);

        $transaction = app(CorrectLedgerEntry::class)->adjust(
            $this->wallet->refresh(),
            $this->manager,
            Money::of(2500, Currency::BDT),
            LedgerDirection::Credit,
            'Goodwill.',
            'Customer complained loudly on social media.',
        );

        $response = $this->actingAs($this->manager)
            ->get(route('admin.wallets.transactions.show', [
                $this->wallet->public_id,
                $transaction->public_id,
            ]));

        $response->assertOk();
        expect($response->getContent())->not->toContain('social media');
    });
});

describe('a manual adjustment (§23.1, §32.2)', function () {
    it('posts, moves the balance and records who and why', function () {
        adminConfirmed($this->manager)
            ->post(route('admin.wallets.adjustments.store', $this->wallet->public_id), [
                'amount_minor' => 2500,
                'direction' => 'credit',
                'reason' => 'Goodwill after a courier failure.',
            ])
            ->assertRedirect();

        $entry = LedgerEntry::query()->where('wallet_id', $this->wallet->id)->firstOrFail();

        expect($this->wallet->refresh()->total_minor->minorUnits)->toBe(2500)
            ->and($entry->type)->toBe(LedgerTransactionType::ManualAdjustment)
            ->and($entry->created_by)->toBe($this->manager->id)
            ->and(AuditLog::query()->where('action', 'wallet.manual_adjustment')->exists())->toBeTrue();
    });

    it('refuses without a reason', function () {
        // Mandatory, not encouraged: a balance that changed for no recorded
        // reason is the first thing an auditor asks about.
        adminConfirmed($this->manager)
            ->post(route('admin.wallets.adjustments.store', $this->wallet->public_id), [
                'amount_minor' => 2500,
                'direction' => 'credit',
            ])
            ->assertSessionHasErrors('reason');

        expect(LedgerEntry::query()->count())->toBe(0);
    });

    it('refuses somebody without the permission', function () {
        adminConfirmed(testPlatformStaff(PlatformRole::PaymentManager))
            ->post(route('admin.wallets.adjustments.store', $this->wallet->public_id), [
                'amount_minor' => 2500,
                'direction' => 'credit',
                'reason' => 'Trying it on.',
            ])
            ->assertForbidden();

        expect(LedgerEntry::query()->count())->toBe(0);
    });

    it('asks for the password again before it will post', function () {
        /*
         * §32.2. A session left open on a shared desk must not be enough to move
         * money, however good the permission behind it.
         */
        $this->actingAs($this->manager)
            ->post(route('admin.wallets.adjustments.store', $this->wallet->public_id), [
                'amount_minor' => 2500,
                'direction' => 'credit',
                'reason' => 'Goodwill after a courier failure.',
            ])
            ->assertRedirect(route('password.confirm'));

        expect(LedgerEntry::query()->count())->toBe(0);
    });

    it('answers a debit it cannot afford rather than overdrawing', function () {
        adminConfirmed($this->manager)
            ->post(route('admin.wallets.adjustments.store', $this->wallet->public_id), [
                'amount_minor' => 5000,
                'direction' => 'debit',
                'reason' => 'Taking back an overpayment.',
            ])
            ->assertSessionHasErrors('amount_minor');

        expect($this->wallet->refresh()->total_minor->minorUnits)->toBe(0);
    });
});

describe('a reversal (§23.2)', function () {
    it('answers the original instead of editing it', function () {
        $transaction = adminCredit($this->wallet, 50000);
        $original = LedgerEntry::query()->latest('id')->firstOrFail();

        adminConfirmed($this->manager)
            ->post(route('admin.wallets.reversals.store', [
                $this->wallet->public_id,
                $transaction->public_id,
            ]), ['reason' => 'Credited to the wrong account.'])
            ->assertRedirect();

        $reversal = LedgerEntry::query()->latest('id')->firstOrFail();

        expect($reversal->id)->not->toBe($original->id)
            ->and($reversal->corrects_ledger_entry_id)->toBe($original->id)
            ->and($original->fresh()->credit_minor->minorUnits)->toBe(50000)
            ->and($this->wallet->refresh()->total_minor->minorUnits)->toBe(0)
            ->and(LedgerEntry::query()->count())->toBe(2);
    });

    it('refuses without a reason', function () {
        $transaction = adminCredit($this->wallet, 50000);

        adminConfirmed($this->manager)
            ->post(route('admin.wallets.reversals.store', [
                $this->wallet->public_id,
                $transaction->public_id,
            ]), [])
            ->assertSessionHasErrors('reason');

        expect(LedgerEntry::query()->count())->toBe(1);
    });

    it('says so when there is nothing posted to reverse', function () {
        // A reservation moved no value, so there is nothing to answer — the
        // operation that applies is releasing it.
        adminCredit($this->wallet, 50000);

        $claim = app(WalletService::class)->reserve(
            $this->wallet->refresh(),
            LedgerTransactionType::ServiceFeeDebit,
            Money::of(20000, Currency::BDT),
            new PostingContext(source: 'test', description: 'Reserved'),
        );

        adminConfirmed($this->manager)
            ->post(route('admin.wallets.reversals.store', [
                $this->wallet->public_id,
                $claim->public_id,
            ]), ['reason' => 'This should not be possible.'])
            ->assertSessionHasErrors('reason');

        expect(LedgerEntry::query()->count())->toBe(1);
    });

    it('refuses somebody without the permission', function () {
        $transaction = adminCredit($this->wallet, 50000);

        adminConfirmed(testPlatformStaff(PlatformRole::PaymentManager))
            ->post(route('admin.wallets.reversals.store', [
                $this->wallet->public_id,
                $transaction->public_id,
            ]), ['reason' => 'Trying it on.'])
            ->assertForbidden();

        expect(LedgerEntry::query()->count())->toBe(1);
    });
});

describe('the administrator export', function () {
    it('is allowed to somebody holding wallet.export', function () {
        adminCredit($this->wallet, 50000, 'A top-up');

        $csv = $this->actingAs($this->manager)
            ->get(route('admin.wallets.download', $this->wallet->public_id))
            ->streamedContent();

        expect($csv)->toContain('A top-up');
    });

    it('is refused to somebody who may only read the screen', function () {
        // A copy of another business's finances leaving the system is its own
        // decision, and its own permission.
        $reader = User::factory()->staff()->withTwoFactor()->create();
        $reader->givePermissionTo('wallet.view');

        $this->actingAs($reader)
            ->get(route('admin.wallets.download', $this->wallet->public_id))
            ->assertForbidden();
    });
});
