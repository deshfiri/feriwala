<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\EvaluateActivationReadiness;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
use App\Notifications\Account\AccountSuspended;
use App\Notifications\Account\KycResubmissionRequested;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();

    $this->seed(RolesAndPermissionsSeeder::class);

    $this->approver = testPlatformStaff(PlatformRole::Admin);
});

describe('the queue', function () {
    it('is closed to a role without the account permission', function () {
        $this->actingAs(User::factory()->withBusinessAccount()->create())
            ->get(route('admin.activations.index'))
            ->assertForbidden();
    });

    it('lists ready accounts, longest wait first', function () {
        $waitingLongest = testAccountReadyForActivation(readyDaysAgo: 9);
        testAccountReadyForActivation(readyDaysAgo: 1);

        $this->actingAs($this->approver)
            ->get(route('admin.activations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/activations/index')
                ->has('accounts.data', 2)
                ->where('accounts.data.0.id', $waitingLongest->public_id)
                ->where('accounts.data.0.waiting_days', 9),
            );
    });

    it('sorts by readiness, not by registration date', function () {
        // Someone who registered months ago but finished paying today has been
        // waiting on us for a day. Sorting by registration inverts the queue
        // exactly where it matters.
        $recentSignupWaitingLongest = testAccountReadyForActivation(readyDaysAgo: 9);
        $recentSignupWaitingLongest->forceFill(['created_at' => now()->subDay()])->save();

        $oldSignupReadyToday = testAccountReadyForActivation(readyDaysAgo: 0);
        $oldSignupReadyToday->forceFill(['created_at' => now()->subMonths(6)])->save();

        $this->actingAs($this->approver)
            ->get(route('admin.activations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.data.0.id', $recentSignupWaitingLongest->public_id),
            );
    });

    it('never lists an account twice, however many times it paid', function () {
        // A join against payments would return one row per settled payment.
        $account = testAccountReadyForActivation();

        Payment::create([
            'business_account_id' => $account->id,
            'purpose' => PaymentPurpose::Activation,
            'status' => PaymentStatus::Paid,
            'amount_minor' => 600000,
            'currency_code' => 'BDT',
            'completed_at' => now(),
        ]);

        $this->actingAs($this->approver)
            ->get(route('admin.activations.index'))
            ->assertInertia(fn (Assert $page) => $page->has('accounts.data', 1));
    });

    it('drops an account whose requirement was reversed', function () {
        // Reversal runs through the same orchestration that put it there — the
        // queue reads state, it does not re-derive eligibility per request.
        $account = testAccountReadyForActivation();

        Payment::where('business_account_id', $account->id)
            ->update(['status' => PaymentStatus::Refunded]);

        app(EvaluateActivationReadiness::class)->handle($account);

        $this->actingAs($this->approver)
            ->get(route('admin.activations.index'))
            ->assertInertia(fn (Assert $page) => $page->has('accounts.data', 0));
    });

    it('picks up an account that met everything before the orchestration existed', function () {
        // The compatibility net. Without it an account that did everything asked
        // of it sits unreachable because no event ever stamped it.
        $account = testAccountReadyForActivation();
        $account->forceFill([
            'status' => AccountStatus::PaymentVerificationPending,
            'approval_pending_at' => null,
        ])->save();

        $this->actingAs($this->approver)
            ->get(route('admin.activations.index'))
            ->assertInertia(fn (Assert $page) => $page->has('accounts.data', 1));
    });

    it('leaves out an account with an outstanding requirement', function () {
        // "Pending" looks like success on a gateway redirect and is not.
        $account = testBusinessAccount(AccountStatus::PaymentVerificationPending);

        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Approved,
            'round' => 1,
        ]);

        Payment::create([
            'business_account_id' => $account->id,
            'purpose' => PaymentPurpose::Activation,
            'status' => PaymentStatus::Pending,
            'amount_minor' => 600000,
            'currency_code' => 'BDT',
        ]);

        $this->actingAs($this->approver)
            ->get(route('admin.activations.index'))
            ->assertInertia(fn (Assert $page) => $page->has('accounts.data', 0));
    });

    it('leaves out an account that is not fully verified', function () {
        $account = testAccountReadyForActivation();
        $account->forceFill([
            'status' => AccountStatus::PaymentVerificationPending,
            'approval_pending_at' => null,
        ])->save();
        $account->owner->forceFill(['mobile_verified_at' => null])->save();

        $this->actingAs($this->approver)
            ->get(route('admin.activations.index'))
            ->assertInertia(fn (Assert $page) => $page->has('accounts.data', 0));
    });

    it('leaves out every ineligible status', function () {
        foreach ([
            AccountStatus::Suspended,
            AccountStatus::Closed,
            AccountStatus::TemporarilyDisabled,
        ] as $status) {
            testAccountReadyForActivation()->forceFill(['status' => $status])->save();
        }

        // And an activated account, excluded by activated_at rather than by
        // listing the twelve post-activation statuses.
        testAccountReadyForActivation()->forceFill([
            'status' => AccountStatus::Active,
            'activated_at' => now(),
        ])->save();

        $this->actingAs($this->approver)
            ->get(route('admin.activations.index'))
            ->assertInertia(fn (Assert $page) => $page->has('accounts.data', 0));
    });

    it('offers the navigation entry to someone who may see accounts', function () {
        $this->actingAs($this->approver)
            ->get(route('admin.activations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('permissions', fn (Assert $permissions) => $permissions
                    ->where('account.view', true)
                    ->etc()),
            );
    });

    it('ignores a sort column that is not on the whitelist', function () {
        $waitingLongest = testAccountReadyForActivation(readyDaysAgo: 9);
        testAccountReadyForActivation(readyDaysAgo: 1);

        $this->actingAs($this->approver)
            ->get(route('admin.activations.index', ['sort' => 'password', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('accounts.data.0.id', $waitingLongest->public_id),
            );
    });
});

describe('the account page', function () {
    it('shows each condition with the evidence behind it', function () {
        $account = testAccountReadyForActivation();

        $this->actingAs($this->approver)
            ->get(route('admin.activations.show', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/activations/show')
                ->has('conditions', 3)
                ->where('account.is_ready', true)
                ->where('account.unmet', [])
                ->where('account.can_approve', true)
                ->where('account.can_suspend', true),
            );
    });

    it('names every unmet condition, not just the first', function () {
        // Nothing done: unverified, no KYC, no payment. All three must be
        // reported so an administrator fixes them at once rather than being
        // refused three times.
        $account = testBusinessAccount(AccountStatus::ApprovalPending);
        $account->owner->forceFill(['email_verified_at' => null, 'mobile_verified_at' => null])->save();

        $this->actingAs($this->approver)
            ->get(route('admin.activations.show', $account))
            ->assertInertia(fn (Assert $page) => $page
                ->where('account.is_ready', false)
                ->has('account.unmet', 3),
            );
    });

    it('offers no decision on the approver’s own account', function () {
        // A reviewer who also owns a business must not decide on it. The
        // policy refuses on membership, not on the account's status.
        $own = testBusinessAccount(AccountStatus::ApprovalPending);
        $own->memberships()->create([
            'user_id' => $this->approver->id,
            'role' => AccountRole::Owner->value,
        ]);

        $this->actingAs($this->approver)
            ->get(route('admin.activations.show', $own))
            ->assertInertia(fn (Assert $page) => $page
                ->where('account.can_approve', false)
                ->where('account.can_suspend', false)
                ->where('account.can_request_resubmission', false),
            );
    });
});

describe('approving', function () {
    it('activates the account and returns to the queue', function () {
        $account = testAccountReadyForActivation();

        $this->actingAs($this->approver)
            ->post(route('admin.activations.approve', $account))
            ->assertRedirect(route('admin.activations.index'));

        expect($account->fresh()->status)->toBe(AccountStatus::Active);
    });

    it('reports the blocking conditions rather than failing opaquely', function () {
        // A condition can come undone between the page rendering and the submit.
        $account = testAccountReadyForActivation();
        Payment::where('business_account_id', $account->id)
            ->update(['status' => PaymentStatus::Pending]);

        $this->actingAs($this->approver)
            ->post(route('admin.activations.approve', $account))
            ->assertSessionHasErrors('activation');

        expect($account->fresh()->status)->toBe(AccountStatus::ApprovalPending);
    });

    it('refuses an approver acting on their own account', function () {
        $own = testBusinessAccount(AccountStatus::ApprovalPending);
        $own->memberships()->create([
            'user_id' => $this->approver->id,
            'role' => AccountRole::Owner->value,
        ]);

        $this->actingAs($this->approver)
            ->post(route('admin.activations.approve', $own))
            ->assertForbidden();
    });

    it('refuses someone who may view but not approve', function () {
        // A KYC Manager holds account.view for context, not account.approve.
        $account = testAccountReadyForActivation();

        $viewer = testPlatformStaff(PlatformRole::KycManager);

        $this->actingAs($viewer)
            ->post(route('admin.activations.approve', $account))
            ->assertForbidden();

        expect($account->fresh()->status)->toBe(AccountStatus::ApprovalPending);
    });
});

describe('requesting corrections', function () {
    it('sends an account back to fix its evidence', function () {
        $account = testAccountReadyForActivation();

        $this->actingAs($this->approver)
            ->post(route('admin.activations.request-resubmission', $account), [
                'reason' => 'Trade licence is expired.',
                'feedback' => 'Please upload a current trade licence.',
            ])
            ->assertRedirect(route('admin.activations.index'));

        expect($account->fresh()->status)->toBe(AccountStatus::KycResubmissionRequired)
            ->and($account->fresh()->approval_pending_at)->toBeNull();
    });

    it('refuses with nothing for the applicant to act on', function () {
        $account = testAccountReadyForActivation();

        $this->actingAs($this->approver)
            ->post(route('admin.activations.request-resubmission', $account), [
                'reason' => 'Trade licence is expired.',
            ])
            ->assertSessionHasErrors('feedback');

        expect($account->fresh()->status)->toBe(AccountStatus::ApprovalPending);
    });

    it('tells the applicant what to fix', function () {
        $account = testAccountReadyForActivation();

        $this->actingAs($this->approver)
            ->post(route('admin.activations.request-resubmission', $account), [
                'reason' => 'Trade licence is expired.',
                'feedback' => 'Please upload a current trade licence.',
                'internal_note' => 'Third attempt.',
            ]);

        Notification::assertSentTo(
            $account->owner,
            KycResubmissionRequested::class,
            // The internal note must not travel with it (§7.3).
            fn (KycResubmissionRequested $notification) => $notification->feedback
                === 'Please upload a current trade licence.',
        );
    });

    it('records its own audit entry', function () {
        $account = testAccountReadyForActivation();

        $this->actingAs($this->approver)
            ->post(route('admin.activations.request-resubmission', $account), [
                'reason' => 'Trade licence is expired.',
                'feedback' => 'Please upload a current trade licence.',
            ]);

        expect(AuditLog::where('action', 'account.kyc_resubmission_requested')->count())->toBe(1);
    });
});

describe('suspending', function () {
    it('is a separate permission from approving', function () {
        // §5.3, D18. Suspension removes the ability to trade and must not be
        // reachable by everyone who happens to work the approval queue.
        $account = testAccountReadyForActivation();

        $approveOnly = User::factory()->staff()->create();
        $approveOnly->givePermissionTo('account.view', 'account.approve');

        $this->actingAs($approveOnly)
            ->post(route('admin.activations.suspend', $account), [
                'reason' => 'Identity documents belong to a different person.',
            ])
            ->assertForbidden();

        // The same role can still ask for corrections.
        $this->actingAs($approveOnly)
            ->post(route('admin.activations.request-resubmission', $account), [
                'reason' => 'Documents unclear.',
                'feedback' => 'Please send a clearer photograph.',
            ])
            ->assertRedirect();
    });

    it('requires a recorded reason', function () {
        $account = testAccountReadyForActivation();

        $this->actingAs($this->approver)
            ->post(route('admin.activations.suspend', $account), [])
            ->assertSessionHasErrors('reason');

        expect($account->fresh()->status)->toBe(AccountStatus::ApprovalPending);
    });

    it('suspends an application that should not proceed', function () {
        $account = testAccountReadyForActivation();

        $this->actingAs($this->approver)
            ->post(route('admin.activations.suspend', $account), [
                'reason' => 'Identity documents belong to a different person.',
                'internal_note' => 'Escalated to compliance.',
            ])
            ->assertRedirect(route('admin.activations.index'));

        expect($account->fresh()->status)->toBe(AccountStatus::Suspended)
            ->and($account->fresh()->approval_pending_at)->toBeNull();
    });

    it('keeps the internal note off the account holder’s record', function () {
        // §7.3 keeps private review notes private.
        $account = testAccountReadyForActivation();

        $this->actingAs($this->approver)
            ->post(route('admin.activations.suspend', $account), [
                'reason' => 'Identity documents belong to a different person.',
                'internal_note' => 'Escalated to compliance.',
            ]);

        $change = $account->statusHistory()->first();

        expect($change->internal_note)->toBe('Escalated to compliance.')
            ->and($change->user_visible_note)->toBeNull();

        Notification::assertSentTo(
            $account->owner,
            AccountSuspended::class,
            fn (AccountSuspended $notification) => $notification->note === null,
        );
    });

    it('records a sensitive audit entry', function () {
        $account = testAccountReadyForActivation();

        $this->actingAs($this->approver)
            ->post(route('admin.activations.suspend', $account), [
                'reason' => 'Identity documents belong to a different person.',
            ]);

        $entry = AuditLog::where('action', 'account.suspended')->first();

        expect($entry)->not->toBeNull()
            ->and($entry->is_sensitive)->toBeTrue()
            ->and($entry->actor_id)->toBe($this->approver->id);
    });

    it('is not a permanent denial', function () {
        // Closure is terminal and belongs to the closure workflow (D18).
        // Suspension must stay reversible or it becomes closure by the back door.
        $account = testAccountReadyForActivation();

        $this->actingAs($this->approver)
            ->post(route('admin.activations.suspend', $account), [
                'reason' => 'Identity documents belong to a different person.',
            ]);

        expect($account->fresh()->status->transitionsTo())->not->toBe([]);
    });
});
