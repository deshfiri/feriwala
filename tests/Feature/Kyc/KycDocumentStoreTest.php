<?php

use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycDocumentAccess;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(KycDocumentStore::DISK);

    $this->store = app(KycDocumentStore::class);

    $this->applicant = User::factory()->create();

    $this->submission = KycSubmission::create([
        'user_id' => $this->applicant->id,
        'status' => KycStatus::Draft,
        'round' => 1,
    ]);

    $this->type = KycDocumentType::create([
        'key' => 'national_id',
        'name' => 'National ID',
        'accepted_mime_types' => ['image/jpeg', 'image/png', 'application/pdf'],
        'max_size_kb' => 2048,
    ]);
});

describe('storing', function () {
    it('stores an accepted file and records its metadata', function () {
        $document = $this->store->store(
            $this->submission,
            $this->type,
            UploadedFile::fake()->image('nid.jpg')->size(500),
        );

        expect($document->exists)->toBeTrue()
            ->and($document->original_name)->toBe('nid.jpg')
            ->and($document->is_encrypted)->toBeTrue()
            ->and($document->checksum)->not->toBeEmpty();

        Storage::disk(KycDocumentStore::DISK)->assertExists($document->path);
    });

    it('refuses a file type the administrator did not allow', function () {
        expect(fn () => $this->store->store(
            $this->submission,
            $this->type,
            UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
        ))->toThrow(InvalidArgumentException::class);
    });

    it('refuses a file over the configured size limit', function () {
        expect(fn () => $this->store->store(
            $this->submission,
            $this->type,
            UploadedFile::fake()->image('huge.jpg')->size(4096),
        ))->toThrow(InvalidArgumentException::class);
    });

    it('writes nothing to disk when the file is refused', function () {
        try {
            $this->store->store(
                $this->submission,
                $this->type,
                UploadedFile::fake()->image('huge.jpg')->size(4096),
            );
        } catch (InvalidArgumentException) {
            // expected
        }

        expect(Storage::disk(KycDocumentStore::DISK)->allFiles())->toBe([])
            ->and(KycDocument::count())->toBe(0);
    });

    it('does not store the file under its original name', function () {
        // An original filename can carry the applicant's own name, and a
        // predictable path is a path someone can try.
        $document = $this->store->store(
            $this->submission,
            $this->type,
            UploadedFile::fake()->image('nusrat-jahan-passport.jpg')->size(100),
        );

        expect($document->path)->not->toContain('nusrat')
            ->and($document->path)->not->toContain('passport');
    });

    it('encrypts the file on disk', function () {
        $document = $this->store->store(
            $this->submission,
            $this->type,
            UploadedFile::fake()->create('nid.pdf', 10, 'application/pdf'),
        );

        $raw = Storage::disk(KycDocumentStore::DISK)->get($document->path);

        // A PDF begins %PDF-. If that is visible, it was not encrypted.
        expect($raw)->not->toStartWith('%PDF');
    });
});

describe('reading', function () {
    beforeEach(function () {
        $this->document = $this->store->store(
            $this->submission,
            $this->type,
            UploadedFile::fake()->image('nid.jpg')->size(100),
        );
    });

    it('returns the original contents', function () {
        expect($this->store->verifyIntegrity($this->document))->toBeTrue();
    });

    it('records who read it (§7.5)', function () {
        $reviewer = User::factory()->create();

        $this->store->read($this->document, $reviewer->id, 'view', '203.0.113.7', 'Firefox');

        $access = KycDocumentAccess::first();

        expect(KycDocumentAccess::count())->toBe(1)
            ->and($access->accessed_by)->toBe($reviewer->id)
            ->and($access->action)->toBe('view')
            ->and($access->ip_address)->toBe('203.0.113.7');
    });

    it('distinguishes a view from a download', function () {
        // Opening a photograph is not the same as taking a copy away.
        $reviewer = User::factory()->create();

        $this->store->read($this->document, $reviewer->id, 'view');
        $this->store->read($this->document, $reviewer->id, 'download');

        expect(KycDocumentAccess::where('action', 'download')->count())->toBe(1)
            ->and(KycDocumentAccess::where('action', 'view')->count())->toBe(1);
    });

    it('records every read, not just the first', function () {
        $reviewer = User::factory()->create();

        $this->store->read($this->document, $reviewer->id);
        $this->store->read($this->document, $reviewer->id);
        $this->store->read($this->document, $reviewer->id);

        expect($this->document->accesses()->count())->toBe(3);
    });

    it('refuses to edit or delete an access record', function () {
        $this->store->read($this->document, User::factory()->create()->id, 'view');
        $access = KycDocumentAccess::first();

        // A genuinely different value — an update to the same value is a no-op
        // that Eloquent skips before any guard runs.
        expect(fn () => $access->update(['action' => 'download']))
            ->toThrow(RuntimeException::class, 'append-only')
            ->and(fn () => $access->delete())
            ->toThrow(RuntimeException::class, 'append-only');

        expect($access->fresh()->action)->toBe('view');
    });
});

describe('leak protection (§7.5)', function () {
    it('hides the storage path from array and JSON output', function () {
        // §7.5 forbids these appearing in public exports. A leaked path lets
        // anyone with bucket access bypass the permission check entirely.
        $document = $this->store->store(
            $this->submission,
            $this->type,
            UploadedFile::fake()->image('nid.jpg')->size(100),
        );

        $serialised = $document->toArray();

        expect($serialised)->not->toHaveKey('path')
            ->and($serialised)->not->toHaveKey('disk')
            ->and($serialised)->not->toHaveKey('checksum')
            ->and(json_encode($document))->not->toContain($document->path);
    });

    it('exposes no method that returns a public url', function () {
        // Deliberate: a url() helper would eventually be called from a view.
        expect(method_exists(KycDocument::class, 'url'))->toBeFalse()
            ->and(method_exists(KycDocument::class, 'getUrl'))->toBeFalse()
            ->and(method_exists(KycDocument::class, 'publicUrl'))->toBeFalse();
    });
});

describe('deletion', function () {
    it('allows removing a document from a draft', function () {
        $document = $this->store->store(
            $this->submission,
            $this->type,
            UploadedFile::fake()->image('nid.jpg')->size(100),
        );

        $this->store->deleteDraftFile($document);

        expect(KycDocument::count())->toBe(0);
    });

    it('refuses to remove a document from a submitted round', function () {
        // Once reviewed, a document is evidence behind a decision (§7.3).
        $document = $this->store->store(
            $this->submission,
            $this->type,
            UploadedFile::fake()->image('nid.jpg')->size(100),
        );

        $this->submission->update(['status' => KycStatus::UnderReview]);

        expect(fn () => $this->store->deleteDraftFile($document->fresh()))
            ->toThrow(InvalidArgumentException::class);
    });
});
