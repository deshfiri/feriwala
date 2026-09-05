<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\AcceptStaffInvitation;
use App\Domain\Account\Actions\InviteStaff;
use App\Domain\Account\Actions\ManageStaff;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Exceptions\StaffLimitReached;
use App\Domain\Account\Models\AccountInvitation;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\StaffAllowance;
use App\Models\User;
use App\Notifications\Account\StaffInvitation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Staff of one business account (P1-65 to P1-68, D1, §8.1, §32).
 *
 * The starter kit's teams are gone: no switcher, no second membership, no
 * account segment in a URL. What replaces them is a single account whose staff
 * are counted against the package that was paid for, and whose owner cannot be
 * removed by the people they invited.
 */

/** Someone who may invite: an owner of an account with room for staff. */
function staffTestOwner(?int $limit = 5): BusinessAccount
{
    return testAccountWithStaffLimit($limit);
}

beforeEach(function () {
    // Platform roles come from the catalogue, not from the factory.
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('the account has no switcher and no URL segment', function () {
    it('gives a person exactly one account', function () {
        $account = staffTestOwner();

        expect($account->owner->businessAccount->id)->toBe($account->id)
            ->and($account->owner->accountRole())->toBe(AccountRole::Owner);
    });

    it('refuses a second membership at the database', function () {
        $first = staffTestOwner();
        $second = staffTestOwner();

        expect(fn () => $second->memberships()->create([
            'user_id' => $first->owner_id,
            'role' => AccountRole::Staff,
        ]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('shares one account rather than a list of them', function () {
        $account = staffTestOwner();

        $this->actingAs($account->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('account.name', $account->name)
                ->where('account.role', 'owner')
                ->missing('teams')
                ->missing('currentTeam'),
            );
    });

    it('has no route that carries an account identifier', function () {
        $withParameters = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => str_contains($uri, '{current_team}')
                || str_contains($uri, '{team}')
                || str_contains($uri, '{account}') && ! str_starts_with($uri, 'admin/'));

        expect($withParameters)->toBeEmpty();
    });

    it('shares nothing for platform staff, who have no account', function () {
        // D23's whole point: administering the platform needs no business.
        $staff = testPlatformStaff(PlatformRole::KycManager);

        $this->actingAs($staff)
            ->get(route('admin.kyc.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('account', null));
    });
});

describe('the staff limit', function () {
    it('does not charge the owner a seat', function () {
        // A limit of one must mean one staff member, not "the owner and nobody".
        $account = testAccountWithStaffLimit(1);
        $allowance = app(StaffAllowance::class);

        expect($allowance->used($account))->toBe(0)
            ->and($allowance->remaining($account))->toBe(1)
            ->and($allowance->hasRoom($account))->toBeTrue();
    });

    it('counts open invitations as well as people who have joined', function () {
        $account = testAccountWithStaffLimit(2);
        $allowance = app(StaffAllowance::class);

        User::factory()->staffOf($account)->create();
        AccountInvitation::factory()->create(['business_account_id' => $account->id]);

        expect($allowance->activeStaff($account))->toBe(1)
            ->and($allowance->openInvitations($account))->toBe(1)
            ->and($allowance->used($account))->toBe(2)
            ->and($allowance->hasRoom($account))->toBeFalse();
    });

    it('stops counting an invitation once it is spent', function (string $state) {
        $account = testAccountWithStaffLimit(1);

        AccountInvitation::factory()->{$state}()->create([
            'business_account_id' => $account->id,
        ]);

        expect(app(StaffAllowance::class)->used($account))->toBe(0);
    })->with(['accepted', 'revoked', 'expired']);

    it('treats a limit of zero as no staff at all, and no limit as unlimited', function () {
        $none = testAccountWithStaffLimit(0);
        $unlimited = testAccountWithStaffLimit(null);
        $allowance = app(StaffAllowance::class);

        expect($allowance->allowsStaff($none))->toBeFalse()
            ->and($allowance->hasRoom($none))->toBeFalse()
            ->and($allowance->allowsStaff($unlimited))->toBeTrue()
            ->and($allowance->remaining($unlimited))->toBeNull();
    });

    it('grants nothing to an account with no package', function () {
        // Still in the funnel, or lapsed. Either way the safe answer is no.
        $account = BusinessAccount::factory()->create();

        expect(app(StaffAllowance::class)->allowsStaff($account))->toBeFalse();
    });
});

describe('inviting staff', function () {
    beforeEach(function () {
        Notification::fake();
    });

    it('sends an invitation and emails the address', function () {
        $account = staffTestOwner();

        $invitation = app(InviteStaff::class)->handle(
            account: $account,
            invitedBy: $account->owner,
            email: 'New.Person@Example.com',
            role: AccountRole::Staff,
        );

        expect($invitation->email)->toBe('new.person@example.com')
            ->and($invitation->isLive())->toBeTrue()
            ->and($invitation->expires_at->isFuture())->toBeTrue();

        Notification::assertSentOnDemand(StaffInvitation::class);
    });

    it('refuses when the package limit is already met', function () {
        $account = testAccountWithStaffLimit(1);
        User::factory()->staffOf($account)->create();

        expect(fn () => app(InviteStaff::class)->handle(
            $account,
            $account->owner,
            'someone@example.com',
        ))->toThrow(StaffLimitReached::class);
    });

    it('cannot be overbooked by sending several invitations at once', function () {
        // The failure this guards: each invitation passes the check on its own,
        // and the limit only breaks when they are all accepted — long after
        // anyone is watching.
        $account = testAccountWithStaffLimit(2);
        $invite = app(InviteStaff::class);

        $invite->handle($account, $account->owner, 'one@example.com');
        $invite->handle($account, $account->owner, 'two@example.com');

        expect(fn () => $invite->handle($account, $account->owner, 'three@example.com'))
            ->toThrow(StaffLimitReached::class);

        expect(AccountInvitation::query()->where('business_account_id', $account->id)->live()->count())
            ->toBe(2);
    });

    it('refuses a second open invitation to the same address', function () {
        $account = staffTestOwner();
        $invite = app(InviteStaff::class);

        $invite->handle($account, $account->owner, 'dup@example.com');

        expect(fn () => $invite->handle($account, $account->owner, 'DUP@example.com'))
            ->toThrow(InvalidArgumentException::class, 'already has an open invitation');
    });

    it('allows a fresh invitation once the previous one is withdrawn', function () {
        $account = staffTestOwner();
        $invite = app(InviteStaff::class);

        $first = $invite->handle($account, $account->owner, 'again@example.com');
        app(ManageStaff::class)->revoke($first, $account->owner);

        $second = $invite->handle($account, $account->owner, 'again@example.com');

        expect($second->id)->not->toBe($first->id)
            ->and($second->isLive())->toBeTrue();
    });

    it('refuses to invite yourself', function () {
        $account = staffTestOwner();

        expect(fn () => app(InviteStaff::class)->handle(
            $account,
            $account->owner,
            $account->owner->email,
        ))->toThrow(InvalidArgumentException::class, 'already in this account');
    });

    it('refuses to invite somebody who already works here', function () {
        $account = staffTestOwner();
        $existing = User::factory()->staffOf($account)->create();

        expect(fn () => app(InviteStaff::class)->handle(
            $account,
            $account->owner,
            mb_strtoupper($existing->email),
        ))->toThrow(InvalidArgumentException::class, 'already in this account');
    });

    it('refuses to invite a second owner', function () {
        $account = staffTestOwner();

        expect(fn () => app(InviteStaff::class)->handle(
            $account,
            $account->owner,
            'owner2@example.com',
            AccountRole::Owner,
        ))->toThrow(InvalidArgumentException::class);
    });
});

describe('accepting an invitation', function () {
    it('joins the account and marks the invitation used', function () {
        $account = staffTestOwner();
        $invited = User::factory()->create(['email' => 'joiner@example.com']);

        $invitation = AccountInvitation::factory()->to('joiner@example.com')->create([
            'business_account_id' => $account->id,
            'role' => AccountRole::Manager,
        ]);

        $membership = app(AcceptStaffInvitation::class)->handle($invitation, $invited);

        expect($membership->role)->toBe(AccountRole::Manager)
            ->and($invited->refresh()->businessAccount->id)->toBe($account->id)
            ->and($invitation->refresh()->isLive())->toBeFalse();
    });

    it('is single use', function () {
        // A link that keeps working is a link that gets forwarded.
        $account = staffTestOwner();
        $invited = User::factory()->create(['email' => 'joiner@example.com']);

        $invitation = AccountInvitation::factory()->to('joiner@example.com')->create([
            'business_account_id' => $account->id,
        ]);

        app(AcceptStaffInvitation::class)->handle($invitation, $invited);

        expect(fn () => app(AcceptStaffInvitation::class)->handle($invitation->refresh(), $invited))
            ->toThrow(InvalidArgumentException::class, 'no longer valid');
    });

    it('refuses a revoked or expired invitation', function (string $state) {
        $account = staffTestOwner();
        $invited = User::factory()->create(['email' => 'joiner@example.com']);

        $invitation = AccountInvitation::factory()->{$state}()->to('joiner@example.com')->create([
            'business_account_id' => $account->id,
        ]);

        expect(fn () => app(AcceptStaffInvitation::class)->handle($invitation, $invited))
            ->toThrow(InvalidArgumentException::class, 'no longer valid');
    })->with(['revoked', 'expired']);

    it('refuses somebody the invitation was not addressed to', function () {
        // A link that works for whoever holds it makes the address decorative,
        // and an invitation grants access to somebody else's business.
        $account = staffTestOwner();
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);

        $invitation = AccountInvitation::factory()->to('joiner@example.com')->create([
            'business_account_id' => $account->id,
        ]);

        expect(fn () => app(AcceptStaffInvitation::class)->handle($invitation, $stranger))
            ->toThrow(InvalidArgumentException::class, 'sent to someone else');
    });

    it('requires the verified mobile when the invitation named one', function () {
        $account = staffTestOwner();

        $unverified = User::factory()->create([
            'email' => 'joiner@example.com',
            'mobile' => '+8801700000001',
            'mobile_verified_at' => null,
        ]);

        $invitation = AccountInvitation::factory()
            ->to('joiner@example.com', '+8801700000001')
            ->create(['business_account_id' => $account->id]);

        expect(fn () => app(AcceptStaffInvitation::class)->handle($invitation, $unverified))
            ->toThrow(InvalidArgumentException::class, 'sent to someone else');
    });

    it('refuses somebody who already works in another account', function () {
        $account = staffTestOwner();

        $elsewhere = testAccountWithStaffLimit(5);
        $person = $elsewhere->owner;
        $person->forceFill(['email' => 'joiner@example.com'])->save();

        $invitation = AccountInvitation::factory()->to('joiner@example.com')->create([
            'business_account_id' => $account->id,
        ]);

        expect(fn () => app(AcceptStaffInvitation::class)->handle($invitation, $person->refresh()))
            ->toThrow(InvalidArgumentException::class, 'already works in another business');
    });

    it('refuses when the seat has gone since the invitation was sent', function () {
        // A package downgraded in the meantime must not be overrun by an
        // acceptance: an invitation is permission to ask, not a reservation
        // that outranks the package.
        $account = testAccountWithStaffLimit(1);
        $invited = User::factory()->create(['email' => 'joiner@example.com']);

        $invitation = AccountInvitation::factory()->to('joiner@example.com')->create([
            'business_account_id' => $account->id,
        ]);

        User::factory()->staffOf($account)->create();

        expect(fn () => app(AcceptStaffInvitation::class)->handle($invitation, $invited))
            ->toThrow(StaffLimitReached::class);
    });
});

describe('the owner', function () {
    it('cannot be removed through staff management', function () {
        $account = staffTestOwner();
        $manager = User::factory()->staffOf($account, AccountRole::Manager)->create();
        $ownerMembership = $account->memberships()->where('role', AccountRole::Owner)->first();

        expect(fn () => app(ManageStaff::class)->remove($ownerMembership, $manager))
            ->toThrow(InvalidArgumentException::class, 'cannot be removed');

        expect($account->memberships()->where('role', AccountRole::Owner)->exists())->toBeTrue();
    });

    it('cannot be demoted through staff management', function () {
        $account = staffTestOwner();
        $manager = User::factory()->staffOf($account, AccountRole::Manager)->create();
        $ownerMembership = $account->memberships()->where('role', AccountRole::Owner)->first();

        expect(fn () => app(ManageStaff::class)->changeRole($ownerMembership, AccountRole::Staff, $manager))
            ->toThrow(InvalidArgumentException::class);

        expect($ownerMembership->refresh()->role)->toBe(AccountRole::Owner);
    });

    it('cannot be created a second time by promotion', function () {
        $account = staffTestOwner();
        $staff = User::factory()->staffOf($account)->create();
        $membership = $account->memberships()->where('user_id', $staff->id)->first();

        expect(fn () => app(ManageStaff::class)->changeRole($membership, AccountRole::Owner, $account->owner))
            ->toThrow(InvalidArgumentException::class, 'Ownership is not granted');
    });
});

describe('managing staff', function () {
    it('removes a staff member and frees the seat', function () {
        $account = testAccountWithStaffLimit(1);
        $staff = User::factory()->staffOf($account)->create();
        $membership = $account->memberships()->where('user_id', $staff->id)->first();

        app(ManageStaff::class)->remove($membership, $account->owner);

        expect($staff->refresh()->businessAccount)->toBeNull()
            ->and(app(StaffAllowance::class)->hasRoom($account))->toBeTrue();
    });

    it('changes a role', function () {
        $account = staffTestOwner();
        $staff = User::factory()->staffOf($account)->create();
        $membership = $account->memberships()->where('user_id', $staff->id)->first();

        app(ManageStaff::class)->changeRole($membership, AccountRole::Manager, $account->owner);

        expect($membership->refresh()->role)->toBe(AccountRole::Manager);
    });

    it('cannot revoke an invitation twice', function () {
        $account = staffTestOwner();
        $invitation = AccountInvitation::factory()->create(['business_account_id' => $account->id]);
        $manage = app(ManageStaff::class);

        $manage->revoke($invitation, $account->owner);

        expect(fn () => $manage->revoke($invitation, $account->owner))
            ->toThrow(InvalidArgumentException::class, 'no longer open');
    });
});

describe('permissions', function () {
    it('lets an owner and a manager manage staff, but not a staff member', function (string $role, bool $may) {
        $account = staffTestOwner();

        $actor = $role === 'owner'
            ? $account->owner
            : User::factory()->staffOf($account, AccountRole::from($role))->create();

        expect($actor->can('invite', [AccountMembership::class, $account]))
            ->toBe($may);
    })->with([
        ['owner', true],
        ['manager', true],
        ['staff', false],
    ]);

    it('gives a manager of one account nothing in another', function () {
        // §31.3: self-scoping is a query concern. A permission check that
        // ignores which account it was asked about is how a manager of one
        // business ends up managing another.
        $mine = staffTestOwner();
        $theirs = staffTestOwner();
        $manager = User::factory()->staffOf($mine, AccountRole::Manager)->create();

        expect($manager->can('invite', [AccountMembership::class, $theirs]))
            ->toBeFalse();
    });

    it('refuses everything on a package with no staff facility', function () {
        $account = testAccountWithStaffLimit(0);

        expect($account->owner->can('invite', [AccountMembership::class, $account]))
            ->toBeFalse()
            ->and($account->owner->can('viewAny', [AccountMembership::class, $account]))
            ->toBeFalse();
    });
});

describe('the staff screen', function () {
    it('lists staff, invitations and the allowance', function () {
        $account = testAccountWithStaffLimit(3);
        $staff = User::factory()->staffOf($account)->create(['name' => 'Rina Akter']);
        AccountInvitation::factory()->to('waiting@example.com')->create([
            'business_account_id' => $account->id,
        ]);

        $this->actingAs($account->owner)
            ->get(route('staff.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/staff')
                ->has('staff', 2)
                ->has('invitations', 1)
                ->where('allowance.limit', 3)
                ->where('allowance.used', 2)
                ->where('can.invite', true),
            );

        expect($staff->accountRole())->toBe(AccountRole::Staff);
    });

    it('shuts out a package with no staff facility', function () {
        $account = testAccountWithStaffLimit(0);

        $this->actingAs($account->owner)->get(route('staff.index'))->assertForbidden();
    });

    it('never offers controls on the owner', function () {
        $account = testAccountWithStaffLimit(3);

        $this->actingAs($account->owner)
            ->get(route('staff.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('staff.0.isOwner', true)
                ->where('staff.0.canManage', false),
            );
    });

    it('offers no owner role to invite or assign', function () {
        $account = testAccountWithStaffLimit(3);

        $this->actingAs($account->owner)
            ->get(route('staff.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('roles', [
                    ['value' => 'manager', 'label' => 'Manager'],
                    ['value' => 'staff', 'label' => 'Staff'],
                ]),
            );
    });

    it('turns away a staff member reaching for somebody in another account', function () {
        $mine = testAccountWithStaffLimit(3);
        $theirs = testAccountWithStaffLimit(3);
        $stranger = User::factory()->staffOf($theirs)->create();

        // Resolves to a real person, and to no membership here. The scoping is
        // in the query, not in a policy compensating for a wide binding.
        $this->actingAs($mine->owner)
            ->delete(route('staff.destroy', $stranger->public_id))
            ->assertNotFound();
    });

    it('rejects an owner role posted straight at the endpoint', function () {
        $account = testAccountWithStaffLimit(3);
        $staff = User::factory()->staffOf($account)->create();

        $this->actingAs($account->owner)
            ->from(route('staff.index'))
            ->patch(route('staff.update', $staff->public_id), ['role' => 'owner'])
            ->assertSessionHasErrors('role');
    });
});

describe('the vocabulary', function () {
    it('names no team in any route', function () {
        $names = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn ($route) => [$route->uri(), $route->getName() ?? ''])
            ->filter(fn (string $value) => str_contains(mb_strtolower($value), 'team'));

        expect($names)->toBeEmpty();
    });

    it('says Account, Staff and Permissions rather than Team and Member', function () {
        expect(AccountRole::Owner->label())->toBe('Owner')
            ->and(AccountRole::Manager->label())->toBe('Manager')
            ->and(AccountRole::Staff->label())->toBe('Staff');
    });
});
