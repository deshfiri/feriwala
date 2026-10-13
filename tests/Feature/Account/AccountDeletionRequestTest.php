<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\DeletionRequestStatus;
use App\Domain\Account\Models\AccountDeletionRequest;
use App\Domain\Notification\Enums\StaffQueue;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Nobody deletes their own account: they ask, and an authorised member of staff
 * decides (§5.3).
 *
 * What these hold: the self-delete routes are gone; a request is self-scoped,
 * password-confirmed and made once; staff who may decide it — and only they —
 * are told, can see it, and can refuse it; nobody decides their own.
 *
 * Helpers are prefixed because Pest loads every test file into one global
 * function namespace.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function deletionTestPartner(): User
{
    return testBusinessAccount(AccountStatus::Active)->owner;
}

/** Somebody who may decide requests from people, but is not Super Admin, so the permission is observable. */
function deletionTestDecider(string ...$permissions): User
{
    $staff = testPlatformStaff(PlatformRole::SmsManager);
    $staff->givePermissionTo($permissions ?: ['account.delete']);

    return $staff;
}

describe('self-deletion is switched off', function () {
    it('has no route that deletes the signed-in account', function () {
        $user = deletionTestPartner();

        expect(Route::has('profile.destroy'))->toBeFalse();

        $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertStatus(405);

        expect($user->fresh())->not->toBeNull()->and($user->fresh()->erased_at)->toBeNull();
    });

    it('offers a Supplier no way to delete itself either', function () {
        $supplier = Supplier::factory()->create();
        supplierTestSignIn($supplier);

        $this->delete('/supplier/profile')->assertStatus(405);

        expect($supplier->fresh())->not->toBeNull();
    });
});

describe('asking to be deleted', function () {
    it('records a request, once, for a person who confirms their password', function () {
        $user = deletionTestPartner();

        $this->actingAs($user)
            ->post(route('account-deletion.store'), ['reason' => 'I am closing my business.', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();

        // Asking again is the same ask.
        $this->actingAs($user)
            ->post(route('account-deletion.store'), ['reason' => 'I am closing my business.', 'current_password' => 'password']);

        $request = AccountDeletionRequest::query()->sole();

        expect($request->user_id)->toBe($user->id)
            ->and($request->status)->toBe(DeletionRequestStatus::Pending)
            ->and($request->reason)->toBe('I am closing my business.');
    });

    it('refuses a wrong password and a missing reason, and records nothing', function () {
        $user = deletionTestPartner();

        $this->actingAs($user)
            ->post(route('account-deletion.store'), ['reason' => 'Closing.', 'current_password' => 'not-my-password'])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($user)
            ->post(route('account-deletion.store'), ['reason' => '', 'current_password' => 'password'])
            ->assertSessionHasErrors('reason');

        expect(AccountDeletionRequest::query()->count())->toBe(0);
    });

    it('lets a Supplier ask on its own guard', function () {
        $supplier = supplierTestSignIn(Supplier::factory()->create());

        $this->post(route('supplier.account-deletion.store'), ['reason' => 'We have stopped trading.', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();

        $request = AccountDeletionRequest::query()->sole();

        expect($request->supplier_id)->toBe($supplier->id)->and($request->user_id)->toBeNull();
    });

    it('shows a person only their own request', function () {
        $mine = deletionTestPartner();
        $theirs = deletionTestPartner();

        $this->actingAs($theirs)->post(route('account-deletion.store'), ['reason' => 'Theirs, not mine.', 'current_password' => 'password']);

        $this->actingAs($mine)->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('deletionRequest', null));

        $this->actingAs($theirs)->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('deletionRequest.status', 'pending')->where('deletionRequest.reason', 'Theirs, not mine.'));
    });

    it('lets a person withdraw their own request, and nobody else\'s', function () {
        $user = deletionTestPartner();
        $other = deletionTestPartner();

        $this->actingAs($user)->post(route('account-deletion.store'), ['reason' => 'Changed my mind soon.', 'current_password' => 'password']);
        $this->actingAs($other)->post(route('account-deletion.store'), ['reason' => 'Not withdrawn by user.', 'current_password' => 'password']);

        $this->actingAs($user)->delete(route('account-deletion.destroy'))->assertSessionHasNoErrors();

        expect(AccountDeletionRequest::query()->where('user_id', $user->id)->sole()->status)->toBe(DeletionRequestStatus::Withdrawn)
            ->and(AccountDeletionRequest::query()->where('user_id', $other->id)->sole()->status)->toBe(DeletionRequestStatus::Pending);
    });
});

describe('the staff queue', function () {
    it('tells the staff who may decide it, and nobody else', function () {
        $decider = deletionTestDecider('account.delete');
        $supplierDecider = deletionTestDecider('supplier.delete');
        $unrelated = testPlatformStaff(PlatformRole::SmsManager);
        $superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs(deletionTestPartner())
            ->post(route('account-deletion.store'), ['reason' => 'Closing the business.', 'current_password' => 'password']);

        expect($decider->notifications()->sole()->data['event'])->toBe('queue.account_deletion')
            ->and($superAdmin->notifications()->count())->toBe(1)
            // The right permission for a different kind of request is not this one.
            ->and($supplierDecider->notifications()->count())->toBe(0)
            ->and($unrelated->notifications()->count())->toBe(0);
    });

    it('tells the Supplier deciders about a Supplier\'s request, with no personal detail', function () {
        $decider = deletionTestDecider('supplier.delete');
        $supplier = supplierTestSignIn(Supplier::factory()->create());

        $this->post(route('supplier.account-deletion.store'), ['reason' => 'We have stopped trading.', 'current_password' => 'password']);

        $notification = $decider->notifications()->sole();

        expect($notification->data['event'])->toBe('queue.supplier_deletion')
            ->and(array_keys($notification->data))->toBe(['event', 'reference'])
            ->and(json_encode($notification->data))->not->toContain($supplier->email)->not->toContain('stopped trading');
    });

    it('counts a request on the dashboard card of the people who may decide it', function () {
        $decider = deletionTestDecider('account.delete');
        $this->actingAs(deletionTestPartner())->post(route('account-deletion.store'), ['reason' => 'Closing the business.', 'current_password' => 'password']);

        $this->actingAs($decider)->get(route('admin.dashboard'))->assertInertia(function (Assert $page) {
            $cards = collect($page->toArray()['props']['cards'])->pluck('value', 'key');

            expect($cards->get('account_deletions'))->toBe(1)->and($cards->has('supplier_deletions'))->toBeFalse();
        });

        expect(StaffQueue::AccountDeletion->waiting()->count())->toBe(1)
            ->and(StaffQueue::SupplierDeletion->waiting()->count())->toBe(0);
    });

    it('lists only the kinds of request the viewer may decide', function () {
        $this->actingAs(deletionTestPartner())->post(route('account-deletion.store'), ['reason' => 'Closing the business.', 'current_password' => 'password']);
        AccountDeletionRequest::create(['supplier_id' => Supplier::factory()->create()->id, 'reason' => 'We have stopped trading.', 'requested_at' => now()]);

        $this->actingAs(deletionTestDecider('account.delete'))->get(route('admin.deletions.index'))
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.kind', 'partner'));

        $this->actingAs(deletionTestDecider('supplier.delete'))->get(route('admin.deletions.index'))
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.kind', 'supplier'));

        $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))->get(route('admin.deletions.index'))
            ->assertInertia(fn (Assert $page) => $page->has('requests.data', 2));
    });

    it('is closed to staff without the permission, and to partners', function () {
        $this->actingAs(deletionTestPartner())->post(route('account-deletion.store'), ['reason' => 'Closing the business.', 'current_password' => 'password']);
        $request = AccountDeletionRequest::query()->sole();

        $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))->get(route('admin.deletions.index'))->assertForbidden();
        $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))->get(route('admin.deletions.show', $request->public_id))->assertForbidden();
        $this->actingAs(deletionTestPartner())->get(route('admin.deletions.index'))->assertForbidden();
        // The right permission for the wrong kind of request.
        $this->actingAs(deletionTestDecider('supplier.delete'))->get(route('admin.deletions.show', $request->public_id))->assertForbidden();
    });

    it('gives Admin and Super Admin the permissions by default', function () {
        expect(testPlatformStaff(PlatformRole::Admin)->can('account.delete'))->toBeTrue()
            ->and(testPlatformStaff(PlatformRole::Admin)->can('supplier.delete'))->toBeTrue()
            ->and(testPlatformStaff(PlatformRole::SuperAdmin)->can('account.delete'))->toBeTrue()
            ->and(testPlatformStaff(PlatformRole::KycManager)->can('account.delete'))->toBeFalse();
    });
});

describe('refusing a request', function () {
    it('needs a note, records the decision and tells the person', function () {
        $user = deletionTestPartner();
        $this->actingAs($user)->post(route('account-deletion.store'), ['reason' => 'Closing the business.', 'current_password' => 'password']);
        $request = AccountDeletionRequest::query()->sole();
        $decider = deletionTestDecider('account.delete');

        $this->actingAs($decider)->post(route('admin.deletions.reject', $request->public_id), ['note' => ''])->assertSessionHasErrors('note');

        $this->actingAs($decider)->post(route('admin.deletions.reject', $request->public_id), ['note' => 'Please settle your pending withdrawal first.'])
            ->assertSessionHasNoErrors();

        $request->refresh();

        expect($request->status)->toBe(DeletionRequestStatus::Rejected)
            ->and($request->decided_by)->toBe($decider->id);

        $told = $user->notifications()->where('data', 'like', '%decision.deletion.rejected%')->sole();

        // What they are told is the note's outcome, never the reviewer.
        expect(json_encode($told->data))->not->toContain((string) $decider->email);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('deletionRequest.status', 'rejected')
                ->where('deletionRequest.decision_note', 'Please settle your pending withdrawal first.'));
    });

    it('cannot be decided twice', function () {
        $this->actingAs(deletionTestPartner())->post(route('account-deletion.store'), ['reason' => 'Closing the business.', 'current_password' => 'password']);
        $request = AccountDeletionRequest::query()->sole();
        $decider = deletionTestDecider('account.delete');

        $this->actingAs($decider)->post(route('admin.deletions.reject', $request->public_id), ['note' => 'Not now.']);
        $this->actingAs($decider)->post(route('admin.deletions.reject', $request->public_id), ['note' => 'Still not.'])->assertSessionHasErrors('note');
    });

    it('never lets somebody decide their own request', function () {
        $decider = deletionTestDecider('account.delete');

        $request = AccountDeletionRequest::create(['user_id' => $decider->id, 'reason' => 'I am leaving the company.', 'requested_at' => now()]);

        $this->actingAs($decider)->post(route('admin.deletions.reject', $request->public_id), ['note' => 'Refusing myself.'])->assertForbidden();
        $this->actingAs($decider)->get(route('admin.deletions.show', $request->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('can_decide', false)->where('impact', null));
    });
});
