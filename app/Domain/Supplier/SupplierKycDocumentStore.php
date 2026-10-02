<?php

namespace App\Domain\Supplier;

use App\Domain\Storage\ManagedStorage;
use App\Domain\Supplier\Models\SupplierKycDocument;
use App\Domain\Supplier\Models\SupplierKycRequirement;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Symfony\Component\Mime\MimeTypes;

/**
 * Stores and reads Supplier KYC documents (D25, mirrors
 * `App\Domain\Kyc\KycDocumentStore`).
 *
 * What may be uploaded comes from the round's snapshot of the administrator's
 * document catalogue ({@see SupplierKycRequirement}). The constants below are
 * only the built-in vocabulary a round falls back to while no Supplier
 * requirement has been configured.
 *
 * Every §7.5-equivalent rule still applies:
 *
 *   - the private `kyc` disk, never `public/`
 *   - a random stored name, never the browser-supplied original
 *   - encrypted at rest
 *   - no method anywhere returns a public URL
 *
 * Resolves through {@see ManagedStorage::diskForLocal()}: moves to Cloudflare
 * R2 the moment that is configured, but keeps landing on the dedicated `kyc`
 * disk while R2 is off.
 */
class SupplierKycDocumentStore
{
    public const DISK = 'kyc';

    /**
     * The closed vocabulary of what a Supplier may upload. A free-text type
     * would accumulate spellings a reviewer's filter can never group again.
     *
     * @var list<string>
     */
    public const DOCUMENT_TYPES = [
        'trade_licence',
        'tin_certificate',
        'nid_front',
        'nid_back',
        'bank_statement',
        'other',
    ];

    /** @var list<string> */
    public const ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

    public const MAX_SIZE_KB = 5120;

    public function __construct(
        protected ManagedStorage $storage,
    ) {}

    /**
     * Judged against the rules the round was opened with (the requirement's
     * snapshot), so an administrator changing a type this morning cannot start
     * refusing a file the Supplier was told would be accepted.
     */
    public function store(
        SupplierKycSubmission $submission,
        SupplierKycRequirement $requirement,
        UploadedFile $file,
    ): SupplierKycDocument {
        $mime = (string) $file->getMimeType();
        $extension = mb_strtolower((string) $file->getClientOriginalExtension());
        $size = (int) $file->getSize();

        // The extension has to belong to one of the accepted formats too, so a
        // script cannot ride in under a document's mime type.
        $allowedExtensions = collect($requirement->accepted_mime_types)
            ->flatMap(fn (string $accepted) => (new MimeTypes)->getExtensions($accepted))
            ->all();

        if (! in_array($mime, $requirement->accepted_mime_types, true) || ! in_array($extension, $allowedExtensions, true)) {
            throw new InvalidArgumentException(sprintf(
                'A %s file is not accepted for %s. Allowed: %s.',
                $mime,
                $requirement->name,
                implode(', ', $requirement->accepted_mime_types),
            ));
        }

        if ($size > $requirement->max_size_kb * 1024) {
            throw new InvalidArgumentException(sprintf(
                'The file is %d KB, which is over the %d KB limit.',
                (int) round($size / 1024),
                $requirement->max_size_kb,
            ));
        }

        $contents = (string) file_get_contents($file->getRealPath());
        $checksum = hash('sha256', $contents);
        $storedBytes = strlen($contents);

        // Random name, never the original — an original filename can carry
        // the applicant's own naming, and a predictable path is one someone
        // could try directly against the disk.
        $path = sprintf(
            'supplier-kyc/%s/%s/%s',
            $submission->supplier_id,
            $submission->public_id,
            bin2hex(random_bytes(16)),
        );

        $this->storage->diskForLocal(self::DISK)->put($path, encrypt($contents));

        return $submission->documents()->create([
            'document_type' => $requirement->key,
            'disk' => $this->storage->diskNameForLocal(self::DISK),
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $storedBytes,
            'checksum' => $checksum,
            'is_encrypted' => true,
        ]);
    }

    /**
     * Read a document's contents. Recording who accessed it is the caller's
     * job here (unlike the generic engine) because a Supplier reading their
     * own document is not the access §7.5 exists to police — only a staff
     * read is, and that happens in the Admin controller.
     */
    public function read(SupplierKycDocument $document): string
    {
        $stored = (string) $this->storage->resolveNamedDisk($document->disk)->get($document->path);

        return $document->is_encrypted ? (string) decrypt($stored) : $stored;
    }

    /**
     * Remove a stored file. Only for a document still on an editable round —
     * once submitted, it is evidence behind a decision and the database
     * trigger `feriwala_supplier_kyc_document_kept()` refuses the delete
     * regardless of what application code does.
     */
    public function deleteDraftFile(SupplierKycDocument $document): void
    {
        $submission = $document->submission()->first();

        if ($submission === null || ! $submission->status->isEditable()) {
            throw new InvalidArgumentException(
                'Only a document on an editable Supplier KYC round can be removed.'
            );
        }

        $this->storage->resolveNamedDisk($document->disk)->delete($document->path);
        $document->delete();
    }
}
