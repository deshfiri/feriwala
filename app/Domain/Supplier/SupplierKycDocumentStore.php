<?php

namespace App\Domain\Supplier;

use App\Domain\Supplier\Models\SupplierKycDocument;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Stores and reads Supplier KYC documents (D25, mirrors
 * `App\Domain\Kyc\KycDocumentStore`).
 *
 * Unlike the generic engine, there is no administrator-configurable document
 * type catalogue behind this — the accepted document types and file rules are
 * fixed here, which is enough for the beta and avoids standing up a second
 * `kyc_document_types` table for one Supplier-only vocabulary.
 *
 * Every §7.5-equivalent rule still applies:
 *
 *   - the private `kyc` disk, never `public/`
 *   - a random stored name, never the browser-supplied original
 *   - encrypted at rest
 *   - no method anywhere returns a public URL
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

    /** @var list<string> */
    public const ACCEPTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'];

    public const MAX_SIZE_KB = 5120;

    public function __construct(
        protected FilesystemFactory $filesystem,
    ) {}

    public function store(
        SupplierKycSubmission $submission,
        string $documentType,
        UploadedFile $file,
    ): SupplierKycDocument {
        if (! in_array($documentType, self::DOCUMENT_TYPES, true)) {
            throw new InvalidArgumentException("[{$documentType}] is not an accepted Supplier KYC document type.");
        }

        $mime = (string) $file->getMimeType();
        $extension = mb_strtolower((string) $file->getClientOriginalExtension());
        $size = (int) $file->getSize();

        if (! in_array($mime, self::ACCEPTED_MIME_TYPES, true) || ! in_array($extension, self::ACCEPTED_EXTENSIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'A %s file is not accepted. Allowed: %s.',
                $mime,
                implode(', ', self::ACCEPTED_EXTENSIONS),
            ));
        }

        if ($size > self::MAX_SIZE_KB * 1024) {
            throw new InvalidArgumentException(sprintf(
                'The file is %d KB, which is over the %d KB limit.',
                (int) round($size / 1024),
                self::MAX_SIZE_KB,
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

        $this->disk()->put($path, encrypt($contents));

        return $submission->documents()->create([
            'document_type' => $documentType,
            'disk' => self::DISK,
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
        $stored = (string) $this->filesystem->disk($document->disk)->get($document->path);

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

        $this->filesystem->disk($document->disk)->delete($document->path);
        $document->delete();
    }

    protected function disk(): Filesystem
    {
        return $this->filesystem->disk(self::DISK);
    }
}
