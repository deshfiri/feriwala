<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Models\AccountReferral;
use App\Domain\Referral\Models\ReferralCommission;
use App\Domain\Referral\Models\ReferralQualifyingEvent;
use App\Domain\Referral\ReferralCode;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The referral screens (D24, P7-10, P7-18, P7-44): staff see the platform,
 * an account sees its own and nothing below its direct referrals.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    referralTestSwitchOn();
    referralTestPlan([['percentage', '10'], ['percentage', '5']]);

    [$this->second, $this->direct] = referralTestChain(2);
    $this->newcomer = referralTestActivate(referralTestNewcomer($this->direct));
    $this->event = ReferralQualifyingEvent::query()->where('source_account_id', $this->newcomer->id)->firstOrFail();

    $this->manager = testPlatformStaff(PlatformRole::ReferralManager);
});

/**
 * Staff who may take commission back: the permission, and a second factor.
 */
function referralScreensReverser(): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo(['referral.view', 'referral.reverse_transaction']);

    return $user;
}

describe('platform commissions', function () {
    it('lists every commission to staff who may see them, filtered on the server', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.referral-commissions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/referral-commissions/index')
                ->has('commissions.data', 2));

        $this->actingAs($this->manager)
            ->get(route('admin.referral-commissions.index', ['level' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('commissions.data', 1)
                ->where('commissions.data.0.beneficiary.name', $this->second->name)
                ->where('commissions.data.0.amount.amount', '325.00')
                ->where('commissions.data.0.rule.percent', '5'));

        $this->actingAs($this->manager)
            ->get(route('admin.referral-commissions.index', ['search' => $this->direct->name, 'status' => 'paid']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('commissions.data', 1)
                ->where('commissions.data.0.level', 1));

        $this->actingAs($this->manager)
            ->get(route('admin.referral-commissions.index', ['from' => now()->addDay()->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page->has('commissions.data', 0));
    });

    it('refuses the records to staff without the permission and to a partner', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::OrderManager))
            ->get(route('admin.referral-commissions.index'))
            ->assertForbidden();

        $this->actingAs($this->direct->owner)
            ->get(route('admin.referral-events.show', $this->event->public_id))
            ->assertForbidden();
    });

    it('shows an event from its trigger to every beneficiary', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.referral-events.show', $this->event->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/referral-commissions/event')
                ->where('event.source.name', $this->newcomer->name)
                ->where('event.base.amount', '6500.00')
                ->where('event.chain.0.account.name', $this->direct->name)
                ->where('event.chain.1.outcome', 'pending')
                ->has('event.commissions', 2)
                // Seeing is not reversing.
                ->where('can_reverse', false));
    });
});

describe('reversal by a person', function () {
    it('asks for the password first, then takes the commission back, audited', function () {
        $reverser = referralScreensReverser();
        $commission = ReferralCommission::query()->where('level', 1)->firstOrFail();

        $this->actingAs($reverser)
            ->post(route('admin.referral-commissions.reverse', $commission->public_id), [
                'cause' => 'fraud', 'reason' => 'Duplicate account abuse confirmed.',
            ])
            ->assertRedirect(route('password.confirm'));

        expect($commission->refresh()->status)->toBe(CommissionStatus::Paid);

        $this->actingAs($reverser)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.referral-commissions.reverse', $commission->public_id), [
                'cause' => 'fraud', 'reason' => 'Duplicate account abuse confirmed.',
            ])
            ->assertRedirect(route('admin.referral-events.show', $this->event->public_id));

        expect($commission->refresh()->status)->toBe(CommissionStatus::Reversed)
            ->and($commission->reversed_by)->toBe($reverser->id)
            ->and(AuditLog::query()->where('action', 'referral.commission_reversed')->where('actor_id', $reverser->id)->exists())->toBeTrue();
    });

    it('sends a person to confirm their password and back to the event, not to the endpoint', function () {
        $reverser = referralScreensReverser();
        $url = route('admin.referral-events.show', $this->event->public_id);

        $this->actingAs($reverser)
            ->get($url)
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_reverse', true)
                ->where('password_confirmed', false))
            ->assertSessionHas('url.intended', $url);

        $this->actingAs($reverser)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get($url)
            ->assertInertia(fn (Assert $page) => $page->where('password_confirmed', true));
    });

    it('refuses a reversal without the permission, without a second factor, or without a reason', function () {
        $commission = ReferralCommission::query()->where('level', 1)->firstOrFail();

        $this->actingAs($this->manager)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.referral-commissions.reverse', $commission->public_id), ['cause' => 'fraud', 'reason' => 'No permission to do this.'])
            ->assertForbidden();

        $withoutTwoFactor = User::factory()->staff()->create();
        $withoutTwoFactor->givePermissionTo(['referral.view', 'referral.reverse_transaction']);

        $this->actingAs($withoutTwoFactor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('admin.referral-events.show', $this->event->public_id))
            ->post(route('admin.referral-commissions.reverse', $commission->public_id), ['cause' => 'fraud', 'reason' => 'Trying without a second factor.'])
            ->assertSessionHasErrors('reason');

        $this->actingAs(referralScreensReverser())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('admin.referral-events.show', $this->event->public_id))
            ->post(route('admin.referral-commissions.reverse', $commission->public_id), ['cause' => 'fraud', 'reason' => 'short'])
            ->assertSessionHasErrors('reason');

        expect($commission->refresh()->status)->toBe(CommissionStatus::Paid);
    });

    it('takes a whole event back', function () {
        $this->actingAs(referralScreensReverser())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.referral-events.reverse', $this->event->public_id), [
                'cause' => 'chargeback', 'reason' => 'The card issuer reversed the activation payment.',
            ])
            ->assertRedirect();

        expect($this->event->refresh()->status)->toBe(ReferralQualifyingEvent::REVERSED)
            ->and(ReferralCommission::query()->where('status', CommissionStatus::Reversed->value)->count())->toBe(2);
    });
});

describe('chain inspection', function () {
    it('shows an account\'s referrer, its chain upward and its direct referrals', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.referral-chains.show', ['account' => $this->newcomer->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/referral-chains')
                ->where('chain.account.name', $this->newcomer->name)
                ->where('chain.link.locked', true)
                ->where('chain.upline.0.name', $this->direct->name)
                ->where('chain.upline.1.name', $this->second->name)
                ->where('can_attach', true));

        $this->actingAs($this->manager)
            ->get(route('admin.referral-chains.show', ['account' => $this->second->public_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('chain.direct_count', 1)
                ->where('chain.direct.0.name', $this->direct->name));
    });

    it('attaches a referrer before the link is locked, and refuses after', function () {
        $unreferred = testBusinessAccount(AccountStatus::Active);

        $this->actingAs($this->manager)
            ->post(route('admin.referral-chains.attach', $unreferred->public_id), [
                'referrer' => $this->second->public_id,
                'reason' => 'Code given by phone at sign-up.',
            ])
            ->assertRedirect();

        expect(AccountReferral::query()->where('referred_account_id', $unreferred->id)->value('referrer_account_id'))->toBe($this->second->id);

        $this->actingAs($this->manager)
            ->from(route('admin.referral-chains.show'))
            ->post(route('admin.referral-chains.attach', $this->newcomer->public_id), [
                'referrer' => $this->second->public_id,
                'reason' => 'Trying to move a locked referral.',
            ])
            ->assertSessionHasErrors('referrer');
    });
});

describe('an account\'s own referrals', function () {
    it('shows the owner their code, direct referrals and own earnings, and nothing deeper', function () {
        $response = $this->actingAs($this->second->owner)->get(route('referrals.index'));
        $code = $this->second->owner->refresh()->referral_code;

        expect($code)->not->toBeNull();

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('referrals/index')
            ->where('code', $code)
            ->where('summary.direct', 1)
            ->where('summary.paid.amount', '325.00')
            ->has('direct.data', 1)
            ->where('direct.data.0.name', $this->direct->name)
            ->where('direct.data.0.state', 'active')
            ->has('earnings.data', 1)
            ->where('earnings.data.0.level', 2)
            ->where('earnings.data.0.status', 'paid'));

        // The level-2 earning says nothing about whose activation paid it.
        $props = json_encode($response->viewData('page')['props']);

        expect($props)->not->toContain($this->newcomer->name)
            ->and($props)->not->toContain($this->newcomer->public_id);
    });

    it('shows nothing of another business and refuses a member without the permission', function () {
        $this->actingAs($this->direct->owner)
            ->get(route('referrals.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paid.amount', '650.00')
                ->where('earnings.data.0.level', 1));

        $staff = User::factory()->staffOf($this->second, AccountRole::Staff)->create();

        $this->actingAs($staff)->get(route('referrals.index'))->assertForbidden();
    });

    it('issues a code to an active owner created without one, once, and never replaces it', function () {
        $owner = $this->second->owner;
        $owner->forceFill(['referral_code' => null])->save();

        $this->actingAs($owner)
            ->get(route('referrals.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('code', fn (string $code) => app(ReferralCode::class)->isWellFormed($code))
                ->where('link', fn (string $link) => str_contains($link, 'ref=')));

        $issued = $owner->refresh()->referral_code;

        $this->actingAs($owner)
            ->get(route('referrals.index'))
            ->assertInertia(fn (Assert $page) => $page->where('code', $issued));

        expect($issued)->not->toBeNull();
    });

    it('offers the page in the owner\'s navigation', function () {
        $this->actingAs($this->second->owner)
            ->get(route('referrals.index'))
            ->assertInertia(fn (Assert $page) => $page->where('account.viewsReferrals', true));
    });
});
