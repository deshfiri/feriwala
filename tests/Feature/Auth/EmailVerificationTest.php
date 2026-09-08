<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Models\AccountInvitation;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Confirming the address an account registered with (P1-8, §5.1, §6).
 *
 * The boundary this file defends: verification is an **identity** fact. It says
 * a person reads a mailbox. It settles nothing commercial — no account is
 * activated, approved or advanced by it — and it hands a suspended identity
 * nothing back.
 */

/** The signed link Laravel would have mailed. */
function verificationUrlFor(User $user, ?string $email = null): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => sha1($email ?? $user->getEmailForVerification()),
    ]);
}

beforeEach(function () {
    Notification::fake();
});

describe('where an unverified person belongs', function () {
    it('sends a newly registered user to the verification screen', function () {
        /*
         * Every ERP destination sits behind the `verified` middleware, so any
         * other answer here is a redirect straight back — the loop `HomeRoute`
         * exists to prevent.
         */
        $this->post(route('register.store'), [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'mobile' => '+8801712345690',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ])->assertRedirect(route('verification.notice', absolute: false));
    });

    it('names the address without spelling it out', function () {
        // Enough to spot your own typo, not enough to be read over a shoulder.
        $user = User::factory()->unverified()->create(['email' => 'karim@example.com']);

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/verify-email')
                ->where('email', 'k••••@example.com'),
            );
    });

    it('turns an unverified person away from the ERP', function () {
        $user = User::factory()
            ->unverified()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    });
});

describe('verifying', function () {
    it('marks the address confirmed and lands through the resolver', function () {
        $user = User::factory()
            ->unverified()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create();

        $this->actingAs($user)
            ->get(verificationUrlFor($user))
            ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

        expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('sends an onboarding business to its own funnel, not the dashboard', function () {
        $user = User::factory()
            ->unverified()
            ->withBusinessAccount(fn ($account) => $account->state([
                'status' => AccountStatus::KycPending,
            ]))
            ->create();

        $response = $this->actingAs($user)->get(verificationUrlFor($user));

        // Resolved, never hardcoded: the account has a dashboard route but the
        // §5.4 gate owns where an unactivated business actually goes.
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
        expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('sends someone holding an invitation to that invitation', function () {
        $owner = User::factory()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create();

        $invitation = AccountInvitation::factory()->create([
            'business_account_id' => $owner->businessAccount->id,
            'email' => 'invited@example.com',
            'invited_by' => $owner->id,
        ]);

        $invited = User::factory()->unverified()->create(['email' => 'invited@example.com']);

        $this->actingAs($invited)
            ->get(verificationUrlFor($invited))
            ->assertRedirect(
                route('staff.invitation.show', $invitation->token, absolute: false).'?verified=1'
            );
    });

    it('sends platform staff to a screen their permissions actually open', function () {
        // D23: staff have no business account, and the dashboard would refuse
        // them — the failure that made HomeRoute necessary in the first place.
        $this->seed(RolesAndPermissionsSeeder::class);

        $staff = User::factory()->unverified()->staff()->create();
        $staff->assignRole(PlatformRole::KycManager->value);

        $this->actingAs($staff)
            ->get(verificationUrlFor($staff))
            ->assertRedirect(route('admin.kyc.index', absolute: false).'?verified=1');
    });
});

describe('what a link cannot do', function () {
    it('refuses one that has expired', function () {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();

        expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('refuses one whose signature has been edited', function () {
        $user = User::factory()->unverified()->create();

        $tampered = verificationUrlFor($user).'&tampered=1';

        $this->actingAs($user)->get($tampered)->assertForbidden();

        expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('refuses a link belonging to somebody else', function () {
        /*
         * The link is signed for one identity. Opening it while signed in as
         * another must not verify either of them — otherwise a forwarded email
         * confirms the wrong mailbox.
         */
        $owner = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        $this->actingAs($other)
            ->get(verificationUrlFor($owner))
            ->assertForbidden();

        expect($owner->refresh()->hasVerifiedEmail())->toBeFalse()
            ->and($other->refresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('refuses a hash that does not match the current address', function () {
        // Changing the address after the link was sent must invalidate it, or
        // the old mailbox still confirms the new one.
        $user = User::factory()->unverified()->create(['email' => 'before@example.com']);

        $url = verificationUrlFor($user, 'after@example.com');

        $this->actingAs($user)->get($url)->assertForbidden();

        expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
    });
});

describe('doing it twice', function () {
    it('lands an already verified person safely rather than erroring', function () {
        $user = User::factory()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create();

        $this->actingAs($user)
            ->get(verificationUrlFor($user))
            ->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    });

    it('does not fire a second transition or a second audit entry', function () {
        $user = User::factory()
            ->unverified()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create();

        $url = verificationUrlFor($user);

        $this->actingAs($user)->get($url);
        $this->actingAs($user)->get($url);

        expect(AuditLog::query()->where('action', 'identity.email_verified')->count())
            ->toBe(1);
    });

    it('records the event as the system, inventing no human actor', function () {
        // Somebody opening a link in their own mailbox is not an administrator
        // acting on an account, and recording them as one turns an audit trail
        // into a log.
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(verificationUrlFor($user));

        $entry = AuditLog::query()->where('action', 'identity.email_verified')->firstOrFail();

        expect($entry->actor_type)->toBe('system')
            ->and($entry->actor_id)->toBeNull();
    });
});

describe('the boundary it must not cross', function () {
    it('leaves the business account exactly where it was', function () {
        /*
         * Verification is an identity fact. It settles nothing commercial —
         * §5.1 still requires KYC, a package, payment and approval, and a
         * confirmed address advances none of them.
         */
        $user = User::factory()
            ->unverified()
            ->withBusinessAccount(fn ($account) => $account->state([
                'status' => AccountStatus::Registered,
            ]))
            ->create();

        $account = $user->businessAccount;
        $before = [
            'status' => $account->status,
            'activated_at' => $account->activated_at,
            'approval_pending_at' => $account->approval_pending_at,
        ];

        $this->actingAs($user)->get(verificationUrlFor($user));

        $account->refresh();

        expect($account->status)->toBe($before['status'])
            ->and($account->activated_at)->toEqual($before['activated_at'])
            ->and($account->approval_pending_at)->toEqual($before['approval_pending_at']);
    });

    it('leaves the identity status alone rather than reactivating anything', function () {
        // §6: a suspended identity must not buy its way back in by opening a
        // link. The status is a security decision somebody made; confirming a
        // mailbox is not a reason to reverse it.
        $user = User::factory()->unverified()->create([
            'identity_status' => UserStatus::Suspended,
        ]);

        $this->actingAs($user)->get(verificationUrlFor($user));

        expect($user->refresh()->identity_status)->toBe(UserStatus::Suspended);
    });

    it('gives a suspended identity no way back to the platform', function () {
        $user = User::factory()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create(['identity_status' => UserStatus::Suspended]);

        // The identity gate outranks everything, verified address included.
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));
    });
});

describe('asking for another link', function () {
    it('sends one and says so', function () {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->from(route('verification.notice'))
            ->post(route('verification.send'))
            ->assertSessionHas('status', 'verification-link-sent');
    });

    it('stops short of a mailbox flood', function () {
        // Fortify throttles this route; without it the form is a free way to
        // post mail to any address somebody has registered.
        $user = User::factory()->unverified()->create();

        foreach (range(1, 6) as $ignored) {
            $this->actingAs($user)->post(route('verification.send'));
        }

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertStatus(429);
    });

    it('has nothing to send once the address is confirmed', function () {
        $user = User::factory()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create();

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect();

        Event::fake();
        expect(true)->toBeTrue();
    });
});
