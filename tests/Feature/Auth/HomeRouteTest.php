<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\AccountInvitation;
use App\Models\User;
use App\Support\Navigation\HomeRoute;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Where a signed-in person lands (D23, §5.4).
 *
 * This exists because of a real lockout. Login redirected everyone to
 * `/dashboard`; the dashboard is business ERP; the §5.4 gate turned an
 * account-less user back to the onboarding stepper; and the stepper refuses
 * anyone without a business account. Three correct-looking steps produced a
 * 403 at the front door for every Feriwala staff member — the exact people D23
 * exists to let in without a business.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('signing in', function () {
    it('lets a platform staff member reach the admin panel', function () {
        // The lockout, asserted end to end.
        $staff = testPlatformStaff(PlatformRole::KycManager);

        $this->post(route('login.store'), [
            'email' => $staff->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.kyc.index', absolute: false));

        $this->assertAuthenticatedAs($staff);
    });

    it('sends a business owner to the dashboard', function () {
        $owner = User::factory()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create();

        $this->post(route('login.store'), [
            'email' => $owner->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));
    });

    it('sends an invitee to the invitation waiting for them', function () {
        $account = testBusinessAccount();
        $invited = User::factory()->create(['email' => 'joiner@example.com']);

        $invitation = AccountInvitation::factory()->to('joiner@example.com')->create([
            'business_account_id' => $account->id,
        ]);

        $this->post(route('login.store'), [
            'email' => $invited->email,
            'password' => 'password',
        ])->assertRedirect(route('staff.invitation.show', $invitation->token, absolute: false));
    });
});

describe('choosing the landing', function () {
    it('prefers a business account over everything else', function () {
        // Most people have one, and it is what they came for.
        $owner = User::factory()->withBusinessAccount()->create();
        $owner->assignRole(PlatformRole::KycManager->value);

        expect(app(HomeRoute::class)->nameFor($owner->refresh()))->toBe('dashboard');
    });

    it('sends staff to the first admin screen they can actually see', function () {
        // Not a fixed page half of them would be refused from.
        $staff = User::factory()->staff()->create();
        $staff->givePermissionTo(PermissionCatalogue::name(
            PermissionModule::Package,
            PermissionAction::View,
        ));

        expect(app(HomeRoute::class)->nameFor($staff->refresh()))
            ->toBe('admin.packages.index');
    });

    it('falls back to a screen every identity can reach', function () {
        /*
         * A login with no business, no invitation and no administrative
         * permission — roles withdrawn, say. Rare is not never, and the
         * alternative is the 403 loop this class exists to prevent.
         */
        $stranded = User::factory()->staff()->create();

        expect(app(HomeRoute::class)->nameFor($stranded))->toBe('profile.edit');
    });

    it('sends a guest to sign in', function () {
        expect(app(HomeRoute::class)->nameFor(null))->toBe('login');
    });

    it('produces a usable URL for an invitation, which needs a token', function () {
        $account = testBusinessAccount();
        $invited = User::factory()->create(['email' => 'joiner@example.com']);

        $invitation = AccountInvitation::factory()->to('joiner@example.com')->create([
            'business_account_id' => $account->id,
        ]);

        expect(app(HomeRoute::class)->urlFor($invited))
            ->toContain($invitation->token);
    });
});

describe('the funnel gate', function () {
    it('no longer pushes account-less staff into the onboarding stepper', function () {
        // The stepper 403s without a business account, so sending them there
        // turned "you cannot see this page" into "you cannot use the product".
        $staff = testPlatformStaff(PlatformRole::KycManager);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertRedirect(route('admin.kyc.index', absolute: false));
    });

    it('still sends an unactivated business to its stepper', function () {
        // The gate's actual job is unchanged.
        $account = testBusinessAccount(AccountStatus::KycPending);

        $this->actingAs($account->owner)
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.status'));
    });

    it('never leaves a staff member in a redirect loop', function () {
        // Following it through: the landing must actually render.
        $staff = testPlatformStaff(PlatformRole::KycManager);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertRedirect(route('admin.kyc.index', absolute: false));

        $this->actingAs($staff)
            ->get(route('admin.kyc.index'))
            ->assertOk();
    });
});
