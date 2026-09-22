<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Referral\Models\ReferralPlan;
use App\Domain\Referral\ReferralSettings;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The multi-level referral configuration screen (D24, P7-12, P7-44).
 *
 * Seeing it and changing it are separate permissions; a partner holds neither.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ReferralManager);
});

/**
 * A staff member who may see the configuration and nothing else.
 */
function referralSettingsViewer(): User
{
    $viewer = User::factory()->staff()->withTwoFactor()->create();
    $viewer->givePermissionTo('referral.view_settings');

    return $viewer;
}

/**
 * A complete open-version submission, overridable field by field.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function referralSettingsPayload(array $overrides = []): array
{
    return [
        'package' => null,
        'trigger' => 'account_activation',
        'commission_base' => 'activation_fees',
        'max_depth' => 3,
        'levels' => [
            ['type' => 'percentage', 'rate_percent' => '10', 'enabled' => '1', 'min_active_direct_referrals' => 0],
            ['type' => 'percentage', 'rate_percent' => '5', 'enabled' => '1', 'min_active_direct_referrals' => 0],
            ['type' => 'fixed', 'amount' => '100.00', 'enabled' => '1', 'min_active_direct_referrals' => 2],
        ],
        'joining_type' => 'fixed',
        'joining_amount' => '50.00',
        'holding_days' => 7,
        'minimum_qualifying_payment' => '0',
        'qualifies_restricted' => '1',
        'effective_from' => now()->addMinute()->toIso8601String(),
        'reason' => 'Launching the three-level plan.',
        ...$overrides,
    ];
}

it('shows the configuration to a person who may see it, without the forms to change it', function () {
    referralTestPlan([['percentage', '10'], ['fixed', 10000, null]]);

    $this->actingAs(referralSettingsViewer())
        ->get(route('admin.referral-settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/referral-settings')
            ->where('enabled', false)
            ->where('can_manage', false)
            ->has('plans.data', 1)
            ->where('plans.data.0.max_depth', 2)
            ->where('plans.data.0.levels.0.reward.percent', '10')
            ->where('plans.data.0.levels.1.reward.amount.minor_units', 10000)
            ->where('plans.data.0.state', 'in_force'));
});

it('refuses the screen to staff without the permission and to a partner', function () {
    $this->actingAs(testPlatformStaff(PlatformRole::OrderManager))
        ->get(route('admin.referral-settings.index'))
        ->assertForbidden();

    $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
        ->get(route('admin.referral-settings.index'))
        ->assertForbidden();
});

it('lets a person who may only see it change nothing', function () {
    $viewer = referralSettingsViewer();

    $this->actingAs($viewer)->post(route('admin.referral-settings.plans.store'), referralSettingsPayload())->assertForbidden();
    $this->actingAs($viewer)->post(route('admin.referral-settings.switch'), ['enabled' => '1', 'reason' => 'Trying to switch it on.'])->assertForbidden();

    expect(ReferralPlan::query()->count())->toBe(0)
        ->and(app(ReferralSettings::class)->enabled())->toBeFalse();
});

it('opens a plan version with a rule for every level, a joining reward and qualification', function () {
    $this->actingAs($this->manager)
        ->post(route('admin.referral-settings.plans.store'), referralSettingsPayload())
        ->assertRedirect(route('admin.referral-settings.index'))
        ->assertSessionHasNoErrors();

    $plan = ReferralPlan::query()->with('levels')->firstOrFail();

    expect($plan->max_depth)->toBe(3)
        ->and($plan->levels->pluck('rate_bps')->all())->toBe([1000, 500, null])
        ->and($plan->levels[2]->amount_minor)->toBe(10000)
        ->and($plan->levels[2]->min_active_direct_referrals)->toBe(2)
        ->and($plan->joining_reward_amount_minor)->toBe(5000)
        ->and($plan->holding_days)->toBe(7)
        ->and($plan->qualifies_restricted)->toBeTrue()
        ->and($plan->qualifies_suspended)->toBeFalse()
        ->and($plan->opened_by)->toBe($this->manager->id)
        ->and(AuditLog::query()->where('action', 'referral.plan_opened')->where('actor_id', $this->manager->id)->exists())->toBeTrue();
});

it('refuses an incomplete, malformed or backdated version and opens nothing', function (array $overrides, string $field) {
    $this->actingAs($this->manager)
        ->from(route('admin.referral-settings.index'))
        ->post(route('admin.referral-settings.plans.store'), referralSettingsPayload($overrides))
        ->assertSessionHasErrors($field);

    expect(ReferralPlan::query()->count())->toBe(0);
})->with([
    'fewer levels than the depth' => [['max_depth' => 5], 'levels'],
    'a percentage that is not a number' => [['levels' => [
        ['type' => 'percentage', 'rate_percent' => 'ten'],
        ['type' => 'percentage', 'rate_percent' => '5'],
        ['type' => 'percentage', 'rate_percent' => '1'],
    ]], 'levels.0'],
    'a percentage above a hundred' => [['levels' => [
        ['type' => 'percentage', 'rate_percent' => '150'],
        ['type' => 'percentage', 'rate_percent' => '5'],
        ['type' => 'percentage', 'rate_percent' => '1'],
    ]], 'levels.0'],
    'a fixed reward of nothing' => [['levels' => [
        ['type' => 'fixed'],
        ['type' => 'percentage', 'rate_percent' => '5'],
        ['type' => 'percentage', 'rate_percent' => '1'],
    ]], 'levels.0'],
    'a start in the past' => [['effective_from' => now()->subDay()->toIso8601String()], 'effective_from'],
    'no reason' => [['reason' => ''], 'reason'],
]);

it('closes a version with a reason', function () {
    $plan = referralTestPlan([['percentage', '10']]);

    $this->actingAs($this->manager)
        ->post(route('admin.referral-settings.plans.close', $plan->public_id), ['reason' => 'Pausing for the audit.'])
        ->assertRedirect();

    expect($plan->refresh()->closed_by)->toBe($this->manager->id)
        ->and($plan->close_reason)->toBe('Pausing for the audit.');
});

it('switches the programme on and off with a reason, audited', function () {
    $this->actingAs($this->manager)
        ->post(route('admin.referral-settings.switch'), ['enabled' => '1', 'reason' => 'Launching after legal review.'])
        ->assertRedirect();

    expect(app(ReferralSettings::class)->enabled())->toBeTrue();

    $this->actingAs($this->manager)
        ->from(route('admin.referral-settings.index'))
        ->post(route('admin.referral-settings.switch'), ['enabled' => '0', 'reason' => 'short'])
        ->assertSessionHasErrors('reason');

    expect(AuditLog::query()->where('action', 'referral.mlm_switched')->count())->toBe(1);
});

it('offers the screen in the staff navigation only to those who may see it', function () {
    $this->actingAs($this->manager)
        ->get(route('admin.referral-settings.index'))
        ->assertInertia(fn (Assert $page) => $page->where('permissions', fn ($permissions) => $permissions['referral.view_settings'] === true));

    $this->actingAs(testPlatformStaff(PlatformRole::OrderManager))
        ->get(route('admin.orders.index'))
        ->assertInertia(fn (Assert $page) => $page->where('permissions', fn ($permissions) => $permissions['referral.view_settings'] === false));
});
