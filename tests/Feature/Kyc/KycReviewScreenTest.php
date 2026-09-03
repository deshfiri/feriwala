<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake(KycDocumentStore::DISK);

    $this->seed(RolesAndPermissionsSeeder::class);

    $this->reviewer = User::factory()->staff()->create();
    $this->reviewer->assignRole(PlatformRole::KycManager->value);
});

/**
 * A submission sitting in the queue, waiting since `$daysAgo`.
 */
function queuedKycSubmission(int $daysAgo = 0, ?User $applicant = null): KycSubmission
{
    return KycSubmission::create([
        'user_id' => ($applicant ?? User::factory()->create())->id,
        'status' => KycStatus::Submitted,
        'round' => 1,
        'submitted_at' => now()->subDays($daysAgo),
    ]);
}

describe('the queue', function () {
    it('is closed to a role without the KYC permission', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.kyc.index'))
            ->assertForbidden();
    });

    it('lists what is waiting, oldest first', function () {
        $oldest = queuedKycSubmission(daysAgo: 10);
        queuedKycSubmission(daysAgo: 1);

        $this->actingAs($this->reviewer)
            ->get(route('admin.kyc.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/kyc/index')
                ->has('submissions.data', 2)
                ->where('submissions.data.0.id', $oldest->public_id)
                ->where('submissions.data.0.waiting_days', 10),
            );
    });

    it('leaves decided rounds out of the queue', function () {
        queuedKycSubmission();

        KycSubmission::create([
            'user_id' => User::factory()->create()->id,
            'status' => KycStatus::Approved,
            'round' => 1,
            'submitted_at' => now()->subDay(),
        ]);

        $this->actingAs($this->reviewer)
            ->get(route('admin.kyc.index'))
            ->assertInertia(fn (Assert $page) => $page->has('submissions.data', 1));
    });

    it('finds an applicant by email', function () {
        $found = User::factory()->create(['email' => 'nusrat@example.test']);
        queuedKycSubmission(applicant: $found);
        queuedKycSubmission();

        $this->actingAs($this->reviewer)
            ->get(route('admin.kyc.index', ['search' => 'nusrat@']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('submissions.data', 1)
                ->where('submissions.data.0.applicant.email', 'nusrat@example.test'),
            );
    });

    it('ignores a sort column that is not on the whitelist', function () {
        // `?sort=` comes from the browser. Anything unrecognised must fall back
        // to the default ordering rather than reaching the ORDER BY.
        $oldest = queuedKycSubmission(daysAgo: 10);
        queuedKycSubmission(daysAgo: 1);

        $this->actingAs($this->reviewer)
            ->get(route('admin.kyc.index', ['sort' => 'users.password', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('submissions.data.0.id', $oldest->public_id),
            );
    });

    it('sorts by a whitelisted column when asked', function () {
        queuedKycSubmission(daysAgo: 10);
        $newest = queuedKycSubmission(daysAgo: 1);

        $this->actingAs($this->reviewer)
            ->get(route('admin.kyc.index', ['sort' => 'submitted_at', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('submissions.data.0.id', $newest->public_id),
            );
    });
});

describe('the submission page', function () {
    it('shows the documents to a reviewer who may open them', function () {
        $submission = queuedKycSubmission();

        $type = KycDocumentType::create([
            'key' => 'national_id',
            'name' => 'National ID',
            'accepted_mime_types' => ['image/jpeg'],
            'max_size_kb' => 2048,
        ]);

        app(KycDocumentStore::class)->store(
            $submission,
            $type,
            UploadedFile::fake()->image('nid.jpg')->size(100),
        );

        $this->actingAs($this->reviewer)
            ->get(route('admin.kyc.show', $submission))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/kyc/show')
                ->has('documents', 1)
                ->where('documents.0.can_open', true)
                ->where('documents.0.original_name', 'nid.jpg')
                ->where('submission.can_review', true),
            );
    });

    it('never sends a storage path to the browser', function () {
        // §7.5. The reviewer gets a link through the controller, not a path.
        $submission = queuedKycSubmission();

        $type = KycDocumentType::create([
            'key' => 'national_id',
            'name' => 'National ID',
            'accepted_mime_types' => ['image/jpeg'],
            'max_size_kb' => 2048,
        ]);

        $document = app(KycDocumentStore::class)->store(
            $submission,
            $type,
            UploadedFile::fake()->image('nid.jpg')->size(100),
        );

        $this->actingAs($this->reviewer)
            ->get(route('admin.kyc.show', $submission))
            ->assertDontSee($document->path, escape: false);
    });

    it('hides document access from a role that only works the queue', function () {
        // A Withdrawal Approver holds kyc.view — enough to check an applicant's
        // standing before releasing a payout, not enough to read their passport.
        $submission = queuedKycSubmission();

        $type = KycDocumentType::create([
            'key' => 'national_id',
            'name' => 'National ID',
            'accepted_mime_types' => ['image/jpeg'],
            'max_size_kb' => 2048,
        ]);

        app(KycDocumentStore::class)->store(
            $submission,
            $type,
            UploadedFile::fake()->image('nid.jpg')->size(100),
        );

        $viewer = User::factory()->staff()->create();
        $viewer->assignRole(PlatformRole::WithdrawalApprover->value);

        $this->actingAs($viewer)
            ->get(route('admin.kyc.show', $submission))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents', 1)
                ->where('documents.0.can_open', false)
                ->where('submission.can_review', false),
            );
    });

    it('offers no decision form on a reviewer’s own submission', function () {
        // Nobody reviews their own KYC, whatever else they hold.
        $submission = queuedKycSubmission(applicant: $this->reviewer);

        $this->actingAs($this->reviewer)
            ->get(route('admin.kyc.show', $submission))
            ->assertInertia(fn (Assert $page) => $page->where('submission.can_review', false));
    });
});

describe('the navigation entry', function () {
    it('is offered to a reviewer', function () {
        // The key is the catalogue name itself, so `permissions.kyc.view` would
        // read as three nested levels. `has` with a closure keeps the assertion
        // on this one entry as more are added.
        $this->actingAs($this->reviewer)
            ->get(route('admin.kyc.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('permissions', fn (Assert $permissions) => $permissions
                    ->where('kyc.view', true)
                    ->etc()),
            );
    });

    it('is withheld from a user with no KYC permission', function () {
        // Asserted on a page they can actually open, since the queue itself
        // would refuse them before any prop was rendered.
        $this->actingAs(User::factory()->create())
            ->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('permissions', fn (Assert $permissions) => $permissions
                    ->where('kyc.view', false)
                    ->etc()),
            );
    });
});

describe('deciding', function () {
    it('records an approval and returns to the queue', function () {
        $submission = queuedKycSubmission();

        $this->actingAs($this->reviewer)
            ->post(route('admin.kyc.decide', $submission), ['outcome' => 'approve'])
            ->assertRedirect(route('admin.kyc.index'));

        expect($submission->fresh()->status)->toBe(KycStatus::Approved);
    });

    it('refuses a rejection with no feedback for the applicant', function () {
        $submission = queuedKycSubmission();

        $this->actingAs($this->reviewer)
            ->post(route('admin.kyc.decide', $submission), [
                'outcome' => 'reject',
                'reason' => 'Document did not match the name on file.',
            ])
            ->assertSessionHasErrors('feedback');

        expect($submission->fresh()->status)->toBe(KycStatus::Submitted);
    });

    it('refuses a decision from someone who may only view', function () {
        $submission = queuedKycSubmission();

        $viewer = User::factory()->staff()->create();
        $viewer->assignRole(PlatformRole::WithdrawalApprover->value);

        $this->actingAs($viewer)
            ->post(route('admin.kyc.decide', $submission), ['outcome' => 'approve'])
            ->assertForbidden();

        expect($submission->fresh()->status)->toBe(KycStatus::Submitted);
    });
});
