<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\AccountInvitation;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
 * Managing a business's own staff from the mobile app (§8.1, D1) and joining one
 * by invitation. The same actions and policies as the web screen; the account
 * is the signed-in person's own, so another business's people are never reachable.
 */

beforeEach(function () {
    $this->account = testAccountWithStaffLimit(5, AccountStatus::Active);
    $this->owner = $this->account->owner;
});

function mobileStaffMember(mixed $account, AccountRole $role = AccountRole::Staff): User
{
    $member = User::factory()->create();
    $account->memberships()->create(['user_id' => $member->id, 'role' => $role]);

    return $member;
}

test('the staff list shows the people, open invitations and the allowance', function () {
    $member = mobileStaffMember($this->account);
    AccountInvitation::factory()->create(['business_account_id' => $this->account->id, 'email' => 'new@example.com']);

    Sanctum::actingAs($this->owner);

    $this->getJson(route('mobile.v1.staff.index'))
        ->assertOk()
        ->assertJsonCount(2, 'staff')
        ->assertJsonPath('staff.0.id', $this->owner->public_id)
        ->assertJsonPath('staff.0.is_owner', true)
        ->assertJsonPath('staff.0.is_you', true)
        ->assertJsonPath('staff.1.id', $member->public_id)
        ->assertJsonPath('invitations.0.email', 'new@example.com')
        ->assertJsonPath('allowance.limit', 5)
        ->assertJsonPath('can.invite', true)
        ->assertJsonMissingPath('staff.0.user_id');
});

test('an invitation is sent, and the owner role cannot be offered', function () {
    Notification::fake();
    Sanctum::actingAs($this->owner);

    $this->postJson(route('mobile.v1.staff.invitations.store'), ['email' => 'Hire@Example.com', 'role' => 'staff'])
        ->assertCreated();

    expect(AccountInvitation::query()->where('business_account_id', $this->account->id)->where('email', 'hire@example.com')->exists())->toBeTrue();

    $this->postJson(route('mobile.v1.staff.invitations.store'), ['email' => 'x@example.com', 'role' => 'owner'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');
});

test('a staff member cannot invite', function () {
    Sanctum::actingAs(mobileStaffMember($this->account));

    $this->postJson(route('mobile.v1.staff.invitations.store'), ['email' => 'a@example.com', 'role' => 'staff'])
        ->assertForbidden();
});

test('an invitation is withdrawn', function () {
    $invitation = AccountInvitation::factory()->create(['business_account_id' => $this->account->id]);
    Sanctum::actingAs($this->owner);

    $this->deleteJson(route('mobile.v1.staff.invitations.destroy', $invitation->public_id))->assertOk();

    expect($invitation->refresh()->revoked_at)->not->toBeNull();
});

test('a role is changed and a member removed', function () {
    $member = mobileStaffMember($this->account);
    Sanctum::actingAs($this->owner);

    $this->patchJson(route('mobile.v1.staff.update', $member->public_id), ['role' => 'manager'])->assertOk();

    expect(AccountMembership::query()->where('user_id', $member->id)->value('role'))->toBe(AccountRole::Manager);

    $this->deleteJson(route('mobile.v1.staff.destroy', $member->public_id))->assertOk();

    expect(AccountMembership::query()->where('user_id', $member->id)->exists())->toBeFalse();
});

test('the owner cannot be removed', function () {
    Sanctum::actingAs($this->owner);

    $this->deleteJson(route('mobile.v1.staff.destroy', $this->owner->public_id))->assertForbidden();
});

test('nobody reaches another business\'s people or invitations by their public id', function () {
    $other = testAccountWithStaffLimit(5, AccountStatus::Active);
    $theirs = mobileStaffMember($other);
    $invitation = AccountInvitation::factory()->create(['business_account_id' => $other->id]);

    Sanctum::actingAs($this->owner);

    $this->patchJson(route('mobile.v1.staff.update', $theirs->public_id), ['role' => 'manager'])->assertNotFound();
    $this->deleteJson(route('mobile.v1.staff.destroy', $theirs->public_id))->assertNotFound();
    $this->deleteJson(route('mobile.v1.staff.invitations.destroy', $invitation->public_id))->assertNotFound();

    expect(AccountMembership::query()->where('user_id', $theirs->id)->exists())->toBeTrue()
        ->and($invitation->refresh()->revoked_at)->toBeNull();
});

test('an invitee reads and accepts an invitation addressed to them', function () {
    $invitee = User::factory()->create();
    $invitation = AccountInvitation::factory()->to($invitee->email)->create(['business_account_id' => $this->account->id]);

    Sanctum::actingAs($invitee);

    $this->getJson(route('mobile.v1.staff.invitation.show', $invitation->token))
        ->assertOk()
        ->assertJsonPath('invitation.account', $this->account->name)
        ->assertJsonPath('invitation.role', 'staff')
        ->assertJsonPath('viewer.matches', true)
        ->assertJsonPath('viewer.has_account', false);

    $this->postJson(route('mobile.v1.staff.invitation.accept', $invitation->token))
        ->assertOk()
        ->assertJsonPath('account', $this->account->name);

    expect($this->account->memberships()->where('user_id', $invitee->id)->exists())->toBeTrue();
});

test('an invitation addressed to someone else is not accepted by a forwarded token', function () {
    $invitation = AccountInvitation::factory()->to('intended@example.com')->create(['business_account_id' => $this->account->id]);
    $stranger = User::factory()->create();

    Sanctum::actingAs($stranger);

    $this->getJson(route('mobile.v1.staff.invitation.show', $invitation->token))
        ->assertOk()
        ->assertJsonPath('viewer.matches', false);

    $this->postJson(route('mobile.v1.staff.invitation.accept', $invitation->token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('invitation');

    expect($this->account->memberships()->where('user_id', $stranger->id)->exists())->toBeFalse();
});

test('an unknown, used or expired invitation is not found', function () {
    $expired = AccountInvitation::factory()->expired()->create(['business_account_id' => $this->account->id]);
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('mobile.v1.staff.invitation.show', 'no-such-token'))->assertNotFound();
    $this->getJson(route('mobile.v1.staff.invitation.show', $expired->token))->assertNotFound();
    $this->postJson(route('mobile.v1.staff.invitation.accept', 'no-such-token'))->assertNotFound();
});

test('staff management is closed until the business is activated', function () {
    $pending = testAccountWithStaffLimit(5, AccountStatus::PaymentVerificationPending);
    Sanctum::actingAs($pending->owner);

    $this->getJson(route('mobile.v1.staff.index'))
        ->assertForbidden()
        ->assertJsonPath('code', 'account_not_activated');
});

test('staff management and invitations need a token', function () {
    $this->getJson(route('mobile.v1.staff.index'))->assertUnauthorized();
    $this->postJson(route('mobile.v1.staff.invitations.store'))->assertUnauthorized();
    $this->getJson(route('mobile.v1.staff.invitation.show', 'token'))->assertUnauthorized();
});
