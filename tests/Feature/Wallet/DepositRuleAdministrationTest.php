<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Actions\CaptureDepositObligation;
use App\Domain\Wallet\Actions\EnforceBalanceRules;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Models\DepositRule;
use App\Domain\Wallet\Models\DepositRuleChange;
use App\Models\User;
use App\Support\Rules\RuleScope;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The administration side of §24 (P2-11 through P2-20).
 *
 * Two screens: where a deposit policy is written, and where an administrator
 * sees what one account is actually held to — including the figures it was
 * restricted against, which are not necessarily the ones in force this morning.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::WalletManager);
});

describe('the rules screen', function () {
    it('shows the scopes in the order that decides them', function () {
        // "Most specific wins" is easy to say and hard to trust without seeing
        // the order.
        $this->actingAs($this->manager)
            ->get(route('admin.deposit-rules.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/deposit-rules')
                ->has('scopes')
                ->has('frequencies')
                ->has('refundabilities')
                ->where('can.manage', true));
    });

    it('is closed to somebody without the wallet permission', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::PaymentManager))
            ->get(route('admin.deposit-rules.index'))
            ->assertForbidden();
    });

    it('is closed to a business user', function () {
        $account = testBusinessAccount(AccountStatus::Active);

        $this->actingAs($account->owner)
            ->get(route('admin.deposit-rules.index'))
            ->assertForbidden();
    });

    it('creates a rule and records why', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.deposit-rules.store'), [
                'scope' => RuleScope::Global->value,
                'required_initial_deposit_minor' => 500000,
                'minimum_balance_minor' => 200000,
                'frequency' => 'one_time',
                'refundability' => 'full',
                'grace_period_days' => 14,
                'restricts_chargeable_services' => true,
                'effective_from' => CarbonImmutable::now()->toDateString(),
                'reason' => 'The standing deposit policy.',
            ])
            ->assertRedirect();

        $rule = DepositRule::query()->firstOrFail();

        expect($rule->minimum_balance_minor->minorUnits)->toBe(200000)
            ->and($rule->grace_period_days)->toBe(14)
            ->and($rule->restricts_chargeable_services)->toBeTrue()
            ->and(DepositRuleChange::query()
                ->where('deposit_rule_id', $rule->id)
                ->where('action', DepositRuleChange::CREATED)
                ->exists())->toBeTrue();
    });

    it('refuses to create one without a reason', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.deposit-rules.store'), [
                'scope' => RuleScope::Global->value,
                'required_initial_deposit_minor' => 0,
                'minimum_balance_minor' => 0,
                'frequency' => 'one_time',
                'refundability' => 'full',
                'effective_from' => CarbonImmutable::now()->toDateString(),
            ])
            ->assertSessionHasErrors('reason');

        expect(DepositRule::query()->count())->toBe(0);
    });

    it('answers an overlapping window rather than failing', function () {
        // The administrator has to close the open rule first, and being told so
        // is an answer to the request.
        $payload = [
            'scope' => RuleScope::Global->value,
            'required_initial_deposit_minor' => 0,
            'minimum_balance_minor' => 100000,
            'frequency' => 'one_time',
            'refundability' => 'full',
            'effective_from' => CarbonImmutable::now()->toDateString(),
            'reason' => 'The standing policy.',
        ];

        $this->actingAs($this->manager)->post(route('admin.deposit-rules.store'), $payload);

        $this->actingAs($this->manager)
            ->post(route('admin.deposit-rules.store'), $payload)
            ->assertSessionHasErrors('effective_from');

        expect(DepositRule::query()->count())->toBe(1);
    });

    it('refuses a change to somebody who may only read', function () {
        $reader = User::factory()->staff()->withTwoFactor()->create();
        $reader->givePermissionTo('wallet.view');

        $this->actingAs($reader)
            ->post(route('admin.deposit-rules.store'), [
                'scope' => RuleScope::Global->value,
                'required_initial_deposit_minor' => 0,
                'minimum_balance_minor' => 0,
                'frequency' => 'one_time',
                'refundability' => 'full',
                'effective_from' => CarbonImmutable::now()->toDateString(),
                'reason' => 'Trying it on.',
            ])
            ->assertForbidden();
    });

    it('closes a rule rather than deleting it', function () {
        $this->actingAs($this->manager)->post(route('admin.deposit-rules.store'), [
            'scope' => RuleScope::Global->value,
            'required_initial_deposit_minor' => 0,
            'minimum_balance_minor' => 100000,
            'frequency' => 'one_time',
            'refundability' => 'full',
            'effective_from' => CarbonImmutable::now()->subWeek()->toDateString(),
            'reason' => 'The standing policy.',
        ]);

        $rule = DepositRule::query()->firstOrFail();

        $this->actingAs($this->manager)
            ->post(route('admin.deposit-rules.close', $rule->public_id), [
                'effective_until' => CarbonImmutable::now()->toDateString(),
                'reason' => 'Replaced by a higher floor.',
            ])
            ->assertRedirect();

        expect(DepositRule::query()->count())->toBe(1)
            ->and($rule->fresh()->effective_until)->not->toBeNull();
    });
});

describe('one account, as an administrator sees it', function () {
    it('shows what the account was actually held to', function () {
        /*
         * The captured figures, not the rule in force this morning. Somebody
         * asking "why is this account restricted" needs the numbers it was
         * restricted against.
         */
        $account = testBusinessAccount(AccountStatus::Active);
        $wallet = app(OpenWallet::class)->handle($account);

        DepositRule::create([
            'scope' => RuleScope::Global,
            'required_initial_deposit_minor' => 300000,
            'minimum_balance_minor' => 100000,
            'grace_period_days' => 7,
            'currency_code' => 'BDT',
            'effective_from' => CarbonImmutable::now()->subMonth(),
            'is_active' => true,
        ]);

        app(CaptureDepositObligation::class)->handle($account);

        DepositRule::query()->update(['minimum_balance_minor' => 900000]);

        $this->actingAs($this->manager)
            ->get(route('admin.wallets.show', $wallet->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('obligation.minimum_balance.minor_units', 100000)
                ->where('obligation.grace_period_days', 7)
                ->has('obligation.captured_at')
                ->where('obligation.rule_scope', 'Global default'));
    });

    it('lists what has been taken away and why', function () {
        $account = testBusinessAccount(AccountStatus::Active);
        $wallet = app(OpenWallet::class)->handle($account);

        DepositRule::create([
            'scope' => RuleScope::Global,
            'required_initial_deposit_minor' => 0,
            'minimum_balance_minor' => 200000,
            'restricts_chargeable_services' => true,
            'currency_code' => 'BDT',
            'effective_from' => CarbonImmutable::now()->subMonth(),
            'is_active' => true,
        ]);

        app(CaptureDepositObligation::class)->handle($account);
        app(EnforceBalanceRules::class)->handle($wallet->refresh());

        $this->actingAs($this->manager)
            ->get(route('admin.wallets.show', $wallet->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('restrictions', 2)
                ->where('restrictions.0.cause', 'low_balance')
                ->has('restrictions.0.stage_label')
                ->where('balances.state', 'critical'));
    });

    it('says nothing is captured when nothing is', function () {
        $account = testBusinessAccount(AccountStatus::Active);
        $wallet = app(OpenWallet::class)->handle($account);

        $this->actingAs($this->manager)
            ->get(route('admin.wallets.show', $wallet->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('obligation', null)
                ->has('restrictions', 0));
    });
});
