<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Models\AccountInvitation;
use App\Models\User;
use App\Support\Navigation\HomeRoute;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;

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

describe('the eight cases that must stay covered', function () {
    it('reaches the onboarding flow for a business still in the funnel', function () {
        // Lands on the dashboard and is redirected by the §5.4 gate. One hop
        // more than necessary — recorded as a refinement on P1-80 — but it
        // arrives, which is what must not regress.
        $account = testBusinessAccount(AccountStatus::KycPending);

        $this->post(route('login.store'), [
            'email' => $account->owner->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->actingAs($account->owner)
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.status'));

        $this->actingAs($account->owner)
            ->get(route('onboarding.status'))
            ->assertOk();
    });

    it('never lands staff on an admin screen they cannot open', function () {
        /*
         * The landing walks the admin screens in order and stops at the first
         * the person can actually see. A fixed page would refuse half of them,
         * which is the lockout in a smaller form.
         */
        $limited = User::factory()->staff()->create();
        $limited->givePermissionTo(PermissionCatalogue::name(
            PermissionModule::Package,
            PermissionAction::View,
        ));

        $landing = app(HomeRoute::class)->nameFor($limited->refresh());

        expect($landing)->toBe('admin.packages.index');

        $this->actingAs($limited)->get(route($landing))->assertOk();

        // And the screens they do not hold stay shut.
        $this->actingAs($limited)->get(route('admin.kyc.index'))->assertForbidden();
    });

    it('does not let a suspended identity in through a direct URL', function () {
        // The identity gate is global and signs them out rather than
        // redirecting, so no landing can be reached by typing one (D23).
        $staff = testPlatformStaff(PlatformRole::KycManager);
        $staff->forceFill(['identity_status' => UserStatus::Suspended])->save();

        $this->actingAs($staff)
            ->get(route('admin.kyc.index'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('does not let a locked identity in either', function () {
        $account = testBusinessAccount();
        $account->owner->forceFill(['identity_status' => UserStatus::Locked])->save();

        $this->actingAs($account->owner->refresh())
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('uses the same resolver when registering', function () {
        /*
         * Registering with an invitation creates no business account, so a
         * fixed /dashboard would drop that person into the §5.4 funnel instead
         * of the invitation they came for.
         *
         * The first answer is now the verification screen (P1-8): the address
         * is unconfirmed, and every other destination sits behind the
         * `verified` middleware. What matters is that both answers come from
         * the resolver rather than from a constant — so the second hop is
         * asserted too.
         */
        $account = testAccountWithStaffLimit(5);

        $invitation = AccountInvitation::factory()->to('joiner@example.com')->create([
            'business_account_id' => $account->id,
        ]);

        $this->post(route('register.store'), [
            'name' => 'New Joiner',
            'email' => 'joiner@example.com',
            'mobile' => '+8801712349999',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
            'invitation' => $invitation->token,
        ])->assertRedirect(route('verification.notice', absolute: false));

        $joiner = User::where('email', 'joiner@example.com')->firstOrFail();
        $joiner->markEmailAsVerified();

        expect(app(HomeRoute::class)->urlFor($joiner->refresh()))
            ->toBe(route('dashboard', absolute: false));
    });

    it('uses the same resolver after verifying an email', function () {
        // Fortify's default is a fixed config('fortify.home'), which is the
        // shape of the bug that locked staff out at login.
        $staff = testPlatformStaff(PlatformRole::KycManager);

        $response = app(VerifyEmailResponseContract::class)->toResponse(
            Request::create('/verify')->setUserResolver(fn () => $staff)
        );

        expect($response->getTargetUrl())
            ->toContain(route('admin.kyc.index', absolute: false));
    });
});
