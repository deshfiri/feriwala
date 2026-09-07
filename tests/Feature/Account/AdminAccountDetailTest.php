<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
use App\Notifications\Kyc\KycUpdateRequested;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The admin account detail screen (P1-79) and the §7.2 request on it (FD-1).
 *
 * The activation queue answers "should this be let in"; this answers "what is
 * going on with this business", about accounts that were let in long ago. That
 * is why the request to re-verify lives here and cannot live there.
 */

/** An activated account with an approved round behind it. */
function dossierTestAccount(): BusinessAccount
{
    $account = testBusinessAccount();

    KycSubmission::create([
        'business_account_id' => $account->id,
        'status' => KycStatus::Approved,
        'round' => 1,
        'submitted_at' => now()->subMonths(11),
        'reviewed_at' => now()->subMonths(11),
    ]);

    return $account;
}

function dossierDocumentType(string $name, bool $required = true): KycDocumentType
{
    return KycDocumentType::factory()->create([
        'name' => $name,
        'is_active' => true,
        'is_required' => $required,
    ]);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->officer = testPlatformStaff(PlatformRole::KycManager);
});

describe('the screen', function () {
    it('gathers the account into one place', function () {
        $account = dossierTestAccount();

        $this->actingAs($this->officer)
            ->get(route('admin.accounts.show', $account->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/accounts/show')
                ->where('business.name', $account->name)
                ->where('business.owner.email', $account->owner->email)
                ->has('kyc_rounds', 1)
                ->has('status_history')
                ->has('payments')
                ->has('staff'),
            );
    });

    it('does not overwrite the shell is idea of whose account the reader is in', function () {
        /*
         * The bug this guards: the page called its payload `account`, the same
         * name `HandleInertiaRequests` shares for the *viewer's* own account. A
         * page prop wins, so the sidebar badge named the business being looked
         * at, and the "My business" group appeared for platform staff who have
         * no account at all. Found by looking at a screenshot, not by a test —
         * hence this one.
         */
        $account = dossierTestAccount();

        $this->actingAs($this->officer)
            ->get(route('admin.accounts.show', $account->public_id))
            ->assertInertia(fn (Assert $page) => $page
                // Platform staff hold no business account, and the shared prop
                // must still say so on this of all screens.
                ->where('account', null)
                ->where('business.name', $account->name),
            );
    });

    it('carries no account identifier a reader could not already reach', function () {
        // §34.2: the URL names the public id, never the database key.
        $account = dossierTestAccount();

        expect(route('admin.accounts.show', $account->public_id, absolute: false))
            ->toBe('/admin/accounts/'.$account->public_id);
    });

    it('refuses the account its own administrative view', function () {
        /*
         * The bug this guards, found by this test: `view` on the policy admits
         * a member, because a member may look at their own account. This screen
         * is not that screen — it carries internal reasons and reviewer notes,
         * and §7.2 forbids those reaching the applicant. An owner opening their
         * own account's admin URL was being shown the lot.
         */
        $account = dossierTestAccount();

        $this->actingAs($account->owner)
            ->get(route('admin.accounts.show', $account->public_id))
            ->assertForbidden();
    });

    it('refuses the activation review page to its own applicant too', function () {
        // The same policy hole, on the screen that lists reviewer internal
        // notes beside each status change.
        $account = testBusinessAccount(AccountStatus::ApprovalPending);

        $this->actingAs($account->owner)
            ->get(route('admin.activations.show', $account->public_id))
            ->assertForbidden();
    });

    it('offers the request only to someone who may make it', function () {
        /*
         * Reading an account and asking it to re-verify are separate powers.
         * Someone who may look at the file — to answer "where has this got to" —
         * does not thereby get to reopen the applicant's verification.
         */
        $account = dossierTestAccount();

        $reader = User::factory()->staff()->create();
        $reader->givePermissionTo(PermissionCatalogue::name(
            PermissionModule::Account,
            PermissionAction::View,
        ));

        $this->actingAs($reader)
            ->get(route('admin.accounts.show', $account->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.request_kyc_update', false),
            );
    });

    it('names the blocker rather than offering a button that will refuse', function () {
        /*
         * Super Admin passes every policy through `Gate::before`, so permission
         * alone would offer the action on an account the action then rejects.
         * The invariant has to be combined with the grant.
         */
        $account = dossierTestAccount();

        KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::Draft,
            'round' => 2,
        ]);

        $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
            ->get(route('admin.accounts.show', $account->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.request_kyc_update', false)
                ->has('blockers', 1),
            );
    });
});

describe('requesting an update', function () {
    it('opens a round, notifies the owner and keeps the account trading', function () {
        $account = dossierTestAccount();
        dossierDocumentType('Trade licence');

        $before = $account->status;

        $this->actingAs($this->officer)
            ->from(route('admin.accounts.show', $account->public_id))
            ->post(route('admin.kyc.request-update', $account->public_id), [
                'reason' => 'Annual re-verification.',
                'instructions' => 'Please upload a current trade licence.',
            ])
            ->assertRedirect(route('admin.accounts.show', $account->public_id));

        expect($account->refresh()->status)->toBe($before);

        Notification::assertSentTo($account->owner, KycUpdateRequested::class);
    });

    it('asks only for the documents that were selected', function () {
        // The point of the selection: an expired licence should not force a
        // business to re-upload every document it has already been cleared on.
        $account = dossierTestAccount();
        $licence = dossierDocumentType('Trade licence');
        dossierDocumentType('Owner photo ID');

        $this->actingAs($this->officer)
            ->post(route('admin.kyc.request-update', $account->public_id), [
                'reason' => 'Licence expired.',
                'instructions' => 'Upload the renewed licence.',
                'document_type_ids' => [$licence->public_id],
            ]);

        $round = KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->where('round', 2)
            ->firstOrFail();

        expect($round->requirements()->pluck('name')->all())
            ->toBe(['Trade licence']);
    });

    it('asks for everything applicable when nothing is singled out', function () {
        $account = dossierTestAccount();
        dossierDocumentType('Trade licence');
        dossierDocumentType('Owner photo ID');

        $this->actingAs($this->officer)
            ->post(route('admin.kyc.request-update', $account->public_id), [
                'reason' => 'Periodic review.',
                'instructions' => 'Upload current copies of everything.',
            ]);

        $round = KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->where('round', 2)
            ->firstOrFail();

        expect($round->requirements()->count())->toBe(2);
    });

    it('refuses a request for no documents at all', function () {
        /*
         * An empty selection read as "everything" would send an account a demand
         * for documents the requester had just deselected. It is a rejected
         * form, not a silent widening.
         */
        $account = dossierTestAccount();
        dossierDocumentType('Trade licence');

        $this->actingAs($this->officer)
            ->from(route('admin.accounts.show', $account->public_id))
            ->post(route('admin.kyc.request-update', $account->public_id), [
                'reason' => 'Nothing in particular.',
                'instructions' => 'Nothing in particular.',
                'document_type_ids' => [],
            ])
            ->assertSessionHasErrors('reason');

        expect(KycSubmission::query()->where('business_account_id', $account->id)->count())
            ->toBe(1);
    });

    it('refuses a document the scope rules say does not apply here', function () {
        // Otherwise an administrator could widen a scope rule through the
        // request form, and the applicant would fail a rule nobody wrote.
        $account = dossierTestAccount();
        $unrelated = dossierDocumentType('Trade licence');
        $unrelated->forceFill(['is_active' => false])->save();

        $this->actingAs($this->officer)
            ->from(route('admin.accounts.show', $account->public_id))
            ->post(route('admin.kyc.request-update', $account->public_id), [
                'reason' => 'Trying it on.',
                'instructions' => 'Upload it anyway.',
                'document_type_ids' => [$unrelated->public_id],
            ])
            ->assertSessionHasErrors('reason');

        expect(KycSubmission::query()->where('business_account_id', $account->id)->count())
            ->toBe(1);
    });

    it('honours a deadline set for this request', function () {
        $account = dossierTestAccount();
        dossierDocumentType('Trade licence');

        $deadline = now()->addDays(7)->toDateString();

        $this->actingAs($this->officer)
            ->post(route('admin.kyc.request-update', $account->public_id), [
                'reason' => 'Urgent.',
                'instructions' => 'Within a week please.',
                'deadline' => $deadline,
            ]);

        $round = KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->where('round', 2)
            ->firstOrFail();

        expect($round->deadline_at->toDateString())->toBe($deadline);
    });

    it('refuses a deadline already in the past', function () {
        $account = dossierTestAccount();

        $this->actingAs($this->officer)
            ->from(route('admin.accounts.show', $account->public_id))
            ->post(route('admin.kyc.request-update', $account->public_id), [
                'reason' => 'Backdated.',
                'instructions' => 'Yesterday.',
                'deadline' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('deadline');
    });
});

describe('what the applicant is never shown', function () {
    it('keeps the internal reason off the account holder is own screen', function () {
        /*
         * The reason is written for the audit trail and for other reviewers.
         * §7.2 is explicit that staff-only material must not reach the applicant
         * view, and the request form is the place it enters the system.
         */
        $account = dossierTestAccount();
        dossierDocumentType('Trade licence');

        $secret = 'Flagged by compliance after a sanctions screening hit.';

        $this->actingAs($this->officer)
            ->post(route('admin.kyc.request-update', $account->public_id), [
                'reason' => $secret,
                'instructions' => 'Please upload a current trade licence.',
            ]);

        $response = $this->actingAs($account->owner)->get(route('kyc.history'));

        $response->assertOk();
        expect($response->getContent())->not->toContain($secret);
    });

    it('does show the applicant the instructions written for them', function () {
        $account = dossierTestAccount();
        dossierDocumentType('Trade licence');

        $instructions = 'Please upload a trade licence valid past March.';

        $this->actingAs($this->officer)
            ->post(route('admin.kyc.request-update', $account->public_id), [
                'reason' => 'Internal only.',
                'instructions' => $instructions,
            ]);

        $this->actingAs($account->owner)
            ->get(route('kyc.history'))
            ->assertOk()
            ->assertSee($instructions, escape: false);
    });

    it('shows an administrator both halves', function () {
        $account = dossierTestAccount();
        dossierDocumentType('Trade licence');

        $this->actingAs($this->officer)
            ->post(route('admin.kyc.request-update', $account->public_id), [
                'reason' => 'Sanctions screening hit.',
                'instructions' => 'Upload a current licence.',
            ]);

        $this->actingAs($this->officer)
            ->get(route('admin.accounts.show', $account->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('kyc_rounds.0.request_reason', 'Sanctions screening hit.')
                ->where('kyc_rounds.0.request_instructions', 'Upload a current licence.')
                ->where('kyc_rounds.0.requested_by', $this->officer->name),
            );
    });
});
