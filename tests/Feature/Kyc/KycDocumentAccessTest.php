<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycDocumentAccess;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(KycDocumentStore::DISK);

    $this->seed(RolesAndPermissionsSeeder::class);

    $this->applicant = User::factory()->create();

    $submission = KycSubmission::create([
        'user_id' => $this->applicant->id,
        'status' => KycStatus::UnderReview,
        'round' => 1,
    ]);

    $type = KycDocumentType::create([
        'key' => 'national_id',
        'name' => 'National ID',
        'accepted_mime_types' => ['image/jpeg'],
        'max_size_kb' => 2048,
    ]);

    $this->document = app(KycDocumentStore::class)->store(
        $submission,
        $type,
        UploadedFile::fake()->image('nid.jpg')->size(100),
    );
});

function reviewerWithRole(PlatformRole $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

describe('who may open a document (§7.5)', function () {
    it('refuses a guest', function () {
        $this->get(route('kyc.documents.show', $this->document))
            ->assertRedirect(route('login'));
    });

    it('refuses a signed-in user with no permission', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('kyc.documents.show', $this->document))
            ->assertForbidden();
    });

    it('refuses a role that can see the queue but not open documents', function () {
        // Seeing that someone is awaiting review is not the same level of
        // intrusion as opening their passport. A Withdrawal Approver holds
        // kyc.view and nothing more, which is exactly that line.
        $this->actingAs(reviewerWithRole(PlatformRole::WithdrawalApprover))
            ->get(route('kyc.documents.show', $this->document))
            ->assertForbidden();
    });

    it('allows the KYC manager', function () {
        $this->actingAs(reviewerWithRole(PlatformRole::KycManager))
            ->get(route('kyc.documents.show', $this->document))
            ->assertOk();
    });

    it('allows the applicant to see their own', function () {
        // They uploaded it; refusing would stop them checking what they sent.
        $this->actingAs($this->applicant)
            ->get(route('kyc.documents.show', $this->document))
            ->assertOk();
    });

    it('refuses one applicant another applicant’s document', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('kyc.documents.show', $this->document))
            ->assertForbidden();
    });
});

describe('recording (§7.5)', function () {
    it('records every view', function () {
        $reviewer = reviewerWithRole(PlatformRole::KycManager);

        $this->actingAs($reviewer)->get(route('kyc.documents.show', $this->document));

        $access = KycDocumentAccess::first();

        expect(KycDocumentAccess::count())->toBe(1)
            ->and($access->accessed_by)->toBe($reviewer->id)
            ->and($access->action)->toBe('view');
    });

    it('distinguishes a download from a view', function () {
        // An investigation should be able to tell "looked at 40 documents"
        // from "downloaded 40 documents".
        $reviewer = reviewerWithRole(PlatformRole::KycManager);

        $this->actingAs($reviewer)->get(route('kyc.documents.show', $this->document));
        $this->actingAs($reviewer)->get(route('kyc.documents.download', $this->document));

        expect(KycDocumentAccess::where('action', 'view')->count())->toBe(1)
            ->and(KycDocumentAccess::where('action', 'download')->count())->toBe(1);
    });

    it('records nothing when access is refused', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('kyc.documents.show', $this->document));

        expect(KycDocumentAccess::count())->toBe(0);
    });
});

describe('the response itself', function () {
    it('is never cached', function () {
        // A KYC document in a shared cache or corporate proxy would undo the
        // storage encryption entirely.
        $response = $this->actingAs(reviewerWithRole(PlatformRole::KycManager))
            ->get(route('kyc.documents.show', $this->document));

        // Symfony normalises and reorders the directives, so assert on each
        // rather than on the exact string.
        $cacheControl = $response->headers->get('Cache-Control');

        foreach (['no-store', 'no-cache', 'must-revalidate', 'private'] as $directive) {
            expect($cacheControl)->toContain($directive);
        }

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    });

    it('serves the original bytes', function () {
        $response = $this->actingAs(reviewerWithRole(PlatformRole::KycManager))
            ->get(route('kyc.documents.show', $this->document));

        expect(strlen($response->getContent()))->toBe($this->document->size_bytes);
    });

    it('is addressed by public id, never the database id', function () {
        expect(route('kyc.documents.show', $this->document))
            ->toContain($this->document->public_id)
            ->and(route('kyc.documents.show', $this->document))
            ->not->toContain('/'.$this->document->id.'/');
    });
});

it('lets a super admin through without holding the permission row', function () {
    $superAdmin = reviewerWithRole(PlatformRole::SuperAdmin);

    $this->actingAs($superAdmin)
        ->get(route('kyc.documents.show', $this->document))
        ->assertOk();
});
