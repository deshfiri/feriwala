<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\UserStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use App\Notifications\Account\IdentityLocked;
use App\Notifications\Account\IdentityUnlocked;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Locking and unlocking a login (P1-17, §6).
 *
 * The identity, not the business. A locked person loses every screen there is,
 * administration included, and their business account stays exactly where it
 * was (D23).
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = testPlatformStaff(PlatformRole::Admin);

    $this->owner = User::factory()
        ->withBusinessAccount(fn ($account) => $account->active())
        ->create();

    $this->account = $this->owner->businessAccount;
});

describe('locking a login', function () {
    it('takes the platform away and records why', function () {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.identities.lock', $this->owner->public_id), [
                'reason' => 'Reported compromised by the account holder.',
            ])
            ->assertRedirect();

        expect($this->owner->refresh()->identity_status)->toBe(UserStatus::Locked)
            ->and($this->owner->identity_status_changed_at)->not->toBeNull();

        $entry = AuditLog::query()->where('action', 'identity.locked')->firstOrFail();

        expect($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->auditable_id)->toBe($this->owner->id)
            ->and($entry->reason)->toBe('Reported compromised by the account holder.')
            ->and($entry->is_sensitive)->toBeTrue();

        Notification::assertSentTo($this->owner, IdentityLocked::class);
    });

    it('ends every session the person had open', function () {
        /*
         * A lock that leaves an open tab working until the person happens to
         * reload is not a lock.
         */
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/131.0'])
            ->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk();

        expect($this->owner->authenticatedSessions()->live()->count())->toBe(1);

        $this->actingAs($this->admin)
            ->post(route('admin.identities.lock', $this->owner->public_id), [
                'reason' => 'Reported compromised by the account holder.',
            ]);

        expect($this->owner->authenticatedSessions()->live()->count())->toBe(0)
            // And only theirs: the administrator making the change is still
            // signed in, on a session this must not have touched.
            ->and($this->admin->authenticatedSessions()->live()->count())->toBe(1);
    });

    it('closes the platform to them on the next request', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.identities.lock', $this->owner->public_id), [
                'reason' => 'Reported compromised by the account holder.',
            ]);

        $this->actingAs($this->owner->refresh())
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('leaves the business exactly where it was', function () {
        // §6 keeps the two lifecycles apart: the person cannot get in, and the
        // company has not been suspended, restricted or told anything.
        $this->actingAs($this->admin)
            ->post(route('admin.identities.lock', $this->owner->public_id), [
                'reason' => 'Reported compromised by the account holder.',
            ]);

        expect($this->account->refresh()->status)->toBe(AccountStatus::Active);
    });

    it('requires a reason', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.identities.lock', $this->owner->public_id), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        expect($this->owner->refresh()->identity_status)->toBe(UserStatus::Active);
    });

    it('never tells the person the internal reason', function () {
        /*
         * §7.3: an assessment recorded for staff is not a message to its
         * subject. The notification says what happened and how to ask; the
         * reason stays in the audit trail.
         */
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.identities.lock', $this->owner->public_id), [
                'reason' => 'Suspected of running a competing marketplace.',
            ]);

        Notification::assertSentTo(
            $this->owner,
            IdentityLocked::class,
            function (IdentityLocked $notification) {
                $payload = json_encode($notification->toArray($this->owner));
                $mail = json_encode($notification->toMail($this->owner)->toArray());

                expect($payload)->not->toContain('competing marketplace')
                    ->and($mail)->not->toContain('competing marketplace');

                return true;
            },
        );
    });
});

describe('restoring a login', function () {
    it('lets them back in and tells them so', function () {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.identities.lock', $this->owner->public_id), [
                'reason' => 'Reported compromised by the account holder.',
            ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.identities.unlock', $this->owner->public_id), [
                'reason' => 'Account holder confirmed their identity by phone.',
            ])
            ->assertRedirect();

        expect($this->owner->refresh()->identity_status)->toBe(UserStatus::Active);

        $entry = AuditLog::query()->where('action', 'identity.unlocked')->firstOrFail();

        expect($entry->reason)->toBe('Account holder confirmed their identity by phone.');

        Notification::assertSentTo($this->owner, IdentityUnlocked::class);
    });

    it('needs its own reason', function () {
        // Restoring access is questioned as hard as removing it, and "it was
        // restored" without "because" is not an answer.
        $this->actingAs($this->admin)
            ->post(route('admin.identities.lock', $this->owner->public_id), [
                'reason' => 'Reported compromised by the account holder.',
            ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.identities.unlock', $this->owner->public_id), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        expect($this->owner->refresh()->identity_status)->toBe(UserStatus::Locked);
    });
});

describe('who may do it', function () {
    it('refuses somebody without the permission', function () {
        $viewer = testPlatformStaff(PlatformRole::ReportViewer);

        $this->actingAs($viewer)
            ->post(route('admin.identities.lock', $this->owner->public_id), [
                'reason' => 'Reported compromised by the account holder.',
            ])
            ->assertForbidden();

        expect($this->owner->refresh()->identity_status)->toBe(UserStatus::Active);
    });

    it('refuses an administrator reaching for a Super Admin', function () {
        /*
         * Otherwise anybody holding `account.edit` could take the platform's
         * last unrestricted login away, and the recovery from that is a
         * database console.
         */
        $superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($this->admin)
            ->post(route('admin.identities.lock', $superAdmin->public_id), [
                'reason' => 'Testing the boundary.',
            ])
            ->assertForbidden();

        expect($superAdmin->refresh()->identity_status)->toBe(UserStatus::Active);
    });

    it('refuses to let anybody lock themselves, Super Admin included', function () {
        /*
         * `Gate::before` passes a Super Admin through every policy, so this rule
         * lives in the action where a grant cannot override it — the same place
         * the KYC document-type deletion guard lives, and for the same reason.
         */
        $superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($superAdmin)
            ->post(route('admin.identities.lock', $superAdmin->public_id), [
                'reason' => 'Locking myself out by mistake.',
            ])
            ->assertSessionHasErrors('reason');

        expect($superAdmin->refresh()->identity_status)->toBe(UserStatus::Active);
    });
});

describe('the account dossier', function () {
    it('lists who can sign in and offers the control', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.accounts.show', $this->account->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/accounts/show')
                ->has('people', 1)
                ->where('people.0.id', $this->owner->public_id)
                ->where('people.0.identity_status', 'active')
                ->where('people.0.is_locked', false)
                ->where('people.0.can_change', true),
            );
    });

    it('does not offer it against a Super Admin', function () {
        $superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
        $this->account->memberships()->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.accounts.show', $this->account->public_id))
            ->assertOk();

        expect($this->admin->can('lock', $superAdmin))->toBeFalse();
    });
});
