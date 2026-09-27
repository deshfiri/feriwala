<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\ReactivateAccount;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Wallet\Models\Wallet;
use App\Models\User;
use App\Notifications\Account\AccountReactivated;
use App\Notifications\Account\AccountSuspended;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * Stopping and restarting a trading business (§5.3).
 *
 * Suspension was reachable only from the activation queue, which holds no
 * trading account — so an activated business could not be suspended from any
 * screen, and a suspended one could not be restored at all. §5.3 calls
 * suspension reversible; until now nothing could reverse it.
 */

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = testPlatformStaff(PlatformRole::Admin);
    $this->account = testBusinessAccount(AccountStatus::Active);
});

describe('suspending a trading account', function () {
    it('suspends from the account screen, with a reason, and tells the owner', function () {
        $this->actingAs($this->staff)
            ->post(route('admin.accounts.suspend', $this->account), [
                'reason' => 'Chargeback pattern under investigation.',
                'feedback' => 'We are reviewing recent payments on your account.',
            ])
            ->assertSessionHasNoErrors();

        expect($this->account->fresh()->status)->toBe(AccountStatus::Suspended);

        $entry = AuditLog::query()->where('action', 'account.suspended')->sole();

        expect($entry->actor_id)->toBe($this->staff->id)
            ->and($entry->is_sensitive)->toBeTrue()
            ->and($entry->reason)->toBe('Chargeback pattern under investigation.');

        Notification::assertSentTo($this->account->owner, AccountSuspended::class);
    });

    it('refuses a suspension with no reason', function () {
        $this->actingAs($this->staff)
            ->post(route('admin.accounts.suspend', $this->account), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        expect($this->account->fresh()->status)->toBe(AccountStatus::Active);
    });

    it('keeps the internal reason out of what the owner is told', function () {
        // §7.3: the private assessment and the customer-facing note are
        // different fields and must stay that way.
        $this->actingAs($this->staff)
            ->post(route('admin.accounts.suspend', $this->account), [
                'reason' => 'Suspected document forgery, escalated to legal.',
                'feedback' => 'Your account is under review.',
            ])
            ->assertSessionHasNoErrors();

        $change = $this->account->statusHistory()
            ->where('to_status', AccountStatus::Suspended)->sole();

        expect($change->reason)->toContain('forgery')
            ->and($change->user_visible_note)->toBe('Your account is under review.')
            ->and($change->user_visible_note)->not->toContain('forgery');
    });

    it('refuses a staff member without the suspension permission', function () {
        $reviewer = User::factory()->staff()->create();
        $reviewer->givePermissionTo(['account.view', 'account.approve']);

        $this->actingAs($reviewer)
            ->post(route('admin.accounts.suspend', $this->account), ['reason' => 'No.'])
            ->assertForbidden();

        expect($this->account->fresh()->status)->toBe(AccountStatus::Active);
    });

    it('refuses someone holding only the activation queue\'s reject permission', function () {
        /*
         * The correction that gave these their own permissions. Declining an
         * applicant at the gate and halting a live business are different
         * decisions with different consequences, and `account.reject` buys
         * only the first.
         */
        $reviewer = User::factory()->staff()->create();
        $reviewer->givePermissionTo(['account.view', 'account.reject']);

        $this->actingAs($reviewer)
            ->post(route('admin.accounts.suspend', $this->account), ['reason' => 'Halt them.'])
            ->assertForbidden();

        $this->post(route('admin.accounts.reactivate', $this->account), ['reason' => 'Restore them.'])
            ->assertForbidden();

        expect($this->account->fresh()->status)->toBe(AccountStatus::Active);
    });

    it('allows a staff member holding account.suspend', function () {
        $reviewer = User::factory()->staff()->create();
        $reviewer->givePermissionTo(['account.view', 'account.suspend']);

        $this->actingAs($reviewer)
            ->post(route('admin.accounts.suspend', $this->account), ['reason' => 'Authorised.'])
            ->assertSessionHasNoErrors();

        expect($this->account->fresh()->status)->toBe(AccountStatus::Suspended);
    });

    it('never lets a signed-in supplier reach either action', function () {
        supplierTestSignIn(Supplier::factory()->create());

        foreach (['admin.accounts.suspend', 'admin.accounts.reactivate'] as $name) {
            $response = $this->post(route($name, $this->account), ['reason' => 'No.']);

            expect($response->getStatusCode())->toBeIn([302, 403]);
        }

        expect($this->account->fresh()->status)->toBe(AccountStatus::Active);
    });

    it('refuses someone suspending their own account', function () {
        $this->actingAs($this->account->owner)
            ->post(route('admin.accounts.suspend', $this->account), ['reason' => 'Mine.'])
            ->assertForbidden();
    });
});

describe('lifting a suspension', function () {
    beforeEach(function () {
        $this->actingAs($this->staff)
            ->post(route('admin.accounts.suspend', $this->account), [
                'reason' => 'Chargeback pattern under investigation.',
            ]);

        $this->account->refresh();
    });

    it('returns the account to active, with a reason, and tells the owner', function () {
        $this->actingAs($this->staff)
            ->post(route('admin.accounts.reactivate', $this->account), [
                'reason' => 'Investigation closed, no wrongdoing found.',
                'feedback' => 'Your account is active again.',
            ])
            ->assertSessionHasNoErrors();

        expect($this->account->fresh()->status)->toBe(AccountStatus::Active);

        $entry = AuditLog::query()->where('action', 'account.reactivated')->sole();

        expect($entry->actor_id)->toBe($this->staff->id)
            ->and($entry->is_sensitive)->toBeTrue()
            ->and($entry->before['status'])->toBe(AccountStatus::Suspended->value)
            ->and($entry->after['status'])->toBe(AccountStatus::Active->value);

        Notification::assertSentTo($this->account->owner, AccountReactivated::class);
    });

    it('refuses a reactivation with no reason', function () {
        $this->actingAs($this->staff)
            ->post(route('admin.accounts.reactivate', $this->account), ['reason' => '   '])
            ->assertSessionHasErrors('reason');

        expect($this->account->fresh()->status)->toBe(AccountStatus::Suspended);
    });

    it('keeps the whole history rather than erasing the suspension', function () {
        $this->actingAs($this->staff)
            ->post(route('admin.accounts.reactivate', $this->account), [
                'reason' => 'Investigation closed.',
            ]);

        $history = $this->account->statusHistory()->reorder('id')->get()
            ->map(fn ($change) => $change->to_status->value)->all();

        // The suspension stays in the record; reactivation is a new row.
        expect($history)->toContain(AccountStatus::Suspended->value)
            ->and($history)->toContain(AccountStatus::Active->value)
            ->and(AuditLog::query()->where('action', 'account.suspended')->count())->toBe(1);
    });

    it('refuses to reactivate an account that is not suspended', function () {
        app(ReactivateAccount::class)->handle($this->account, $this->staff->id, 'Lifted.');

        // A second attempt is refused under the row lock, so two reviewers
        // lifting at once resolve to one winner and the loser writes nothing.
        expect(fn () => app(ReactivateAccount::class)
            ->handle($this->account->refresh(), $this->staff->id, 'Again.'))
            ->toThrow(InvalidArgumentException::class, 'Only a suspended account');

        expect(AuditLog::query()->where('action', 'account.reactivated')->count())->toBe(1);
    });

    it('refuses a staff member without the suspension permission', function () {
        $reviewer = User::factory()->staff()->create();
        $reviewer->givePermissionTo(['account.view', 'account.approve']);

        $this->actingAs($reviewer)
            ->post(route('admin.accounts.reactivate', $this->account), ['reason' => 'Let them back.'])
            ->assertForbidden();

        expect($this->account->fresh()->status)->toBe(AccountStatus::Suspended);
    });

    it('grants nothing a first activation would have granted', function () {
        // Reactivation returns an account to where it was. It opens no second
        // wallet and starts no second subscription, because those happened the
        // first time.
        $walletsBefore = Wallet::query()
            ->where('business_account_id', $this->account->id)->count();

        app(ReactivateAccount::class)->handle($this->account, $this->staff->id, 'Lifted.');

        expect(Wallet::query()
            ->where('business_account_id', $this->account->id)->count())
            ->toBe($walletsBefore);
    });
});

describe('who holds the new permissions by default', function () {
    it('grants both to Admin, so a reversible decision stays reversible', function () {
        expect($this->staff->can('account.suspend'))->toBeTrue()
            ->and($this->staff->can('account.reactivate'))->toBeTrue();
    });

    it('gives Super Admin both through the existing role mechanism', function () {
        $superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        expect($superAdmin->can('account.suspend'))->toBeTrue()
            ->and($superAdmin->can('account.reactivate'))->toBeTrue()
            ->and($superAdmin->can('suspendTrading', $this->account))->toBeTrue()
            ->and($superAdmin->can('reactivate', $this->account))->toBeTrue();
    });

    it('gives a read-only account role neither', function () {
        // A KYC manager reads dossiers and decides verification; halting a
        // live business is not part of that job.
        $kycManager = testPlatformStaff(PlatformRole::KycManager);

        expect($kycManager->can('account.suspend'))->toBeFalse()
            ->and($kycManager->can('account.reactivate'))->toBeFalse();
    });

    it('treats both as sensitive, so the §32.2 escalation applies', function () {
        expect(PermissionAction::Suspend->isSensitive())->toBeTrue()
            ->and(PermissionAction::Reactivate->isSensitive())->toBeTrue();
    });

    it('leaves the activation queue on its own permission', function () {
        // The queue decides about applicants and keeps `account.reject`;
        // nothing here widened what that permission buys.
        $reviewer = User::factory()->staff()->create();
        $reviewer->givePermissionTo(['account.view', 'account.reject']);

        $applicant = testBusinessAccount(AccountStatus::ApprovalPending);

        expect($reviewer->can('suspend', $applicant))->toBeTrue()
            ->and($reviewer->can('suspendTrading', $applicant))->toBeFalse();
    });
});

describe('what the account screen offers', function () {
    it('offers suspend for a trading account and reactivate for a suspended one', function () {
        $this->actingAs($this->staff)
            ->get(route('admin.accounts.show', $this->account))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.suspend', true)
                ->where('can.reactivate', false));

        $this->post(route('admin.accounts.suspend', $this->account), ['reason' => 'Under review.']);

        $this->get(route('admin.accounts.show', $this->account))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Mutually exclusive by status: offering both would offer one
                // the action will certainly refuse.
                ->where('can.suspend', false)
                ->where('can.reactivate', true));
    });

    it('offers neither to a staff member who cannot suspend', function () {
        $reviewer = User::factory()->staff()->create();
        $reviewer->givePermissionTo(['account.view']);

        $this->actingAs($reviewer)
            ->get(route('admin.accounts.show', $this->account))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.suspend', false)
                ->where('can.reactivate', false));
    });
});
