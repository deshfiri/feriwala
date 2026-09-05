<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake(KycDocumentStore::DISK);

    $this->account = testBusinessAccount(AccountStatus::KycPending);
    $this->applicant = $this->account->owner;
    $this->applicant->forceFill(['country' => 'BD'])->save();

    $this->nid = KycDocumentType::create([
        'key' => 'national_id',
        'name' => 'National ID',
        'is_required' => true,
        'accepted_mime_types' => ['image/jpeg', 'image/png'],
        'max_size_kb' => 2048,
    ]);
});

describe('the form', function () {
    it('is closed to guests', function () {
        $this->get(route('kyc.create'))->assertRedirect(route('login'));
    });

    it('opens a draft and lists what this applicant must provide', function () {
        $this->actingAs($this->applicant)
            ->get(route('kyc.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/kyc')
                ->where('submission.round', 1)
                ->where('submission.is_editable', true)
                ->has('requirements', 1)
                ->where('requirements.0.name', 'National ID'),
            );

        expect(KycSubmission::where('business_account_id', $this->account->id)->count())->toBe(1);
    });

    it('reuses the open draft rather than starting a new round', function () {
        // Revisiting the form must not strand documents on an abandoned round.
        $this->actingAs($this->applicant)->get(route('kyc.create'));
        $this->actingAs($this->applicant)->get(route('kyc.create'));

        expect(KycSubmission::where('business_account_id', $this->account->id)->count())->toBe(1);
    });

    it('omits a requirement scoped to another country', function () {
        $other = KycDocumentType::create([
            'key' => 'trade_licence',
            'name' => 'Trade Licence',
            'accepted_mime_types' => ['application/pdf'],
            'max_size_kb' => 2048,
        ]);
        $other->scopes()->create(['scope_type' => 'country', 'scope_value' => 'IN']);

        $this->actingAs($this->applicant)
            ->get(route('kyc.create'))
            ->assertInertia(fn (Assert $page) => $page->has('requirements', 1));
    });

    it('never sends a storage path to the browser', function () {
        // §7.5. The form shows that a file exists, not where it lives.
        $submission = KycSubmission::create([
            'business_account_id' => $this->account->id,
            'status' => KycStatus::Draft,
            'round' => 1,
        ]);

        app(KycDocumentStore::class)->store(
            $submission,
            $this->nid,
            UploadedFile::fake()->image('nid.jpg')->size(100),
        );

        $document = KycDocument::first();

        $this->actingAs($this->applicant)
            ->get(route('kyc.create'))
            ->assertOk()
            ->assertDontSee($document->path, escape: false)
            ->assertSee('nid.jpg', escape: false);
    });
});

describe('uploading', function () {
    it('attaches a document to the draft', function () {
        $this->actingAs($this->applicant)
            ->post(route('kyc.documents.store'), [
                'document_type' => 'national_id',
                'file' => UploadedFile::fake()->image('nid.jpg')->size(100),
            ])
            ->assertRedirect();

        expect(KycDocument::count())->toBe(1);
    });

    it('rejects a file the administrator did not allow, naming the limit', function () {
        // The message has to tell the applicant how to fix it.
        $response = $this->actingAs($this->applicant)
            ->post(route('kyc.documents.store'), [
                'document_type' => 'national_id',
                'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            ]);

        $response->assertSessionHasErrors('file');
        expect(session('errors')->first('file'))->toContain('image/jpeg');
    });

    it('keeps earlier uploads when a later one is rejected', function () {
        // The whole reason uploads save one at a time.
        $this->actingAs($this->applicant)->post(route('kyc.documents.store'), [
            'document_type' => 'national_id',
            'file' => UploadedFile::fake()->image('nid.jpg')->size(100),
        ]);

        $this->actingAs($this->applicant)->post(route('kyc.documents.store'), [
            'document_type' => 'national_id',
            'file' => UploadedFile::fake()->create('bad.pdf', 100, 'application/pdf'),
        ]);

        expect(KycDocument::count())->toBe(1);
    });

    it('refuses to change a submission under review', function () {
        KycSubmission::create([
            'business_account_id' => $this->account->id,
            'status' => KycStatus::UnderReview,
            'round' => 1,
        ]);

        $this->actingAs($this->applicant)
            ->post(route('kyc.documents.store'), [
                'document_type' => 'national_id',
                'file' => UploadedFile::fake()->image('nid.jpg')->size(100),
            ])
            ->assertSessionHasErrors('file');
    });
});

describe('submitting', function () {
    it('sends a complete round for review', function () {
        $this->actingAs($this->applicant)->post(route('kyc.documents.store'), [
            'document_type' => 'national_id',
            'file' => UploadedFile::fake()->image('nid.jpg')->size(100),
        ]);

        $this->actingAs($this->applicant)
            ->post(route('kyc.submit'))
            ->assertRedirect(route('onboarding.status'));

        expect(KycSubmission::first()->status)->toBe(KycStatus::Submitted)
            ->and($this->account->fresh()->status)->toBe(AccountStatus::KycSubmitted);
    });

    it('refuses an incomplete round and says what is missing', function () {
        $response = $this->actingAs($this->applicant)->post(route('kyc.submit'));

        $response->assertSessionHasErrors('submission');
        expect(session('errors')->first('submission'))->toContain('National ID');
    });
});

it('is not indexable', function () {
    $this->actingAs($this->applicant)
        ->get(route('kyc.create'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
});
