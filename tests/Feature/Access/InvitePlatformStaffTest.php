<?php

use App\Domain\Access\Actions\InvitePlatformStaff;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use App\Notifications\Access\PlatformStaffInvited;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
 * Creating a brand-new platform staff login (Platform Staff management).
 * An administrator never chooses or sees this person's password (§6) --
 * the real one is only ever set by the invitee themselves.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->actor = testPlatformStaff(PlatformRole::SystemAdministrator);
});

it('creates a platform staff login with the given roles, and no business account', function () {
    Notification::fake();

    $user = app(InvitePlatformStaff::class)->handle(
        actor: $this->actor,
        name: 'Karim Ahmed',
        email: 'karim.ahmed@example.test',
        mobile: '+8801700000001',
        roleNames: [PlatformRole::SmsManager->value],
        reason: 'Joining the SMS team.',
    );

    expect($user->name)->toBe('Karim Ahmed')
        ->and($user->email)->toBe('karim.ahmed@example.test')
        ->and($user->mobile)->toBe('+8801700000001')
        ->and($user->hasRole(PlatformRole::SmsManager->value))->toBeTrue()
        ->and($user->accountMembership()->exists())->toBeFalse();
});

it('never lets the admin see or choose a password -- it is random and unusable', function () {
    Notification::fake();

    $first = app(InvitePlatformStaff::class)->handle(
        actor: $this->actor,
        name: 'Karim Ahmed',
        email: 'karim1@example.test',
        mobile: null,
        roleNames: [PlatformRole::SmsManager->value],
        reason: 'Joining the SMS team.',
    );

    $second = app(InvitePlatformStaff::class)->handle(
        actor: $this->actor,
        name: 'Fatema Begum',
        email: 'fatema1@example.test',
        mobile: null,
        roleNames: [PlatformRole::SmsManager->value],
        reason: 'Joining the SMS team.',
    );

    expect($first->password)->not->toBe($second->password)
        ->and(Hash::check('password', $first->password))->toBeFalse();
});

it('sends a password-setup notification carrying a real broker token', function () {
    Notification::fake();

    $user = app(InvitePlatformStaff::class)->handle(
        actor: $this->actor,
        name: 'Karim Ahmed',
        email: 'karim2@example.test',
        mobile: null,
        roleNames: [PlatformRole::SmsManager->value],
        reason: 'Joining the SMS team.',
    );

    Notification::assertSentTo(
        $user,
        PlatformStaffInvited::class,
        fn (PlatformStaffInvited $notification) => Password::broker('users')->tokenExists($user, $notification->token),
    );
});

it('resends the invite on demand', function () {
    Notification::fake();

    $user = app(InvitePlatformStaff::class)->handle(
        actor: $this->actor,
        name: 'Karim Ahmed',
        email: 'karim3@example.test',
        mobile: null,
        roleNames: [PlatformRole::SmsManager->value],
        reason: 'Joining the SMS team.',
    );

    app(InvitePlatformStaff::class)->sendInvite($user);

    Notification::assertSentToTimes($user, PlatformStaffInvited::class, 2);
});

it('records the invite in the audit trail, alongside the role grant', function () {
    Notification::fake();

    $user = app(InvitePlatformStaff::class)->handle(
        actor: $this->actor,
        name: 'Karim Ahmed',
        email: 'karim4@example.test',
        mobile: null,
        roleNames: [PlatformRole::SmsManager->value],
        reason: 'Joining the SMS team.',
    );

    expect(AuditLog::query()->where('action', 'access.staff_invited')->where('auditable_id', $user->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'access.role_assigned')->where('auditable_id', $user->id)->exists())->toBeTrue();
});

it('refuses an empty role set', function () {
    Notification::fake();

    expect(fn () => app(InvitePlatformStaff::class)->handle(
        actor: $this->actor,
        name: 'Karim Ahmed',
        email: 'karim5@example.test',
        mobile: null,
        roleNames: [],
        reason: 'Should be refused.',
    ))->toThrow(InvalidArgumentException::class);

    expect(User::query()->where('email', 'karim5@example.test')->exists())->toBeFalse();
});

it('refuses to invite someone as Super Admin unless the inviting actor already holds it, and does not leave a stray user behind', function () {
    Notification::fake();

    expect(fn () => app(InvitePlatformStaff::class)->handle(
        actor: $this->actor,
        name: 'Karim Ahmed',
        email: 'karim6@example.test',
        mobile: null,
        roleNames: [PlatformRole::SuperAdmin->value],
        reason: 'Should be refused.',
    ))->toThrow(InvalidArgumentException::class);

    expect(User::query()->where('email', 'karim6@example.test')->exists())->toBeFalse();
});
