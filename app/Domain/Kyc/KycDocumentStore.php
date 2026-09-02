<?php

namespace App\Domain\Kyc;

use App\Domain\Kyc\Models\KycDocument;
use App\Domain\Kyc\Models\KycDocumentAccess;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Stores and reads KYC documents (§7.5).
 *
 * Every rule §7.5 sets is enforced here rather than left to callers:
 *
 *   - files go to a **private** disk, never `public/`
 *   - the stored name is random, so guessing a path is not possible even for
 *     someone who reaches the storage directly
 *   - the file is encrypted at rest
 *   - reading requires going through {@see read()}, which records the access
 *
 * There is deliberately no method returning a URL. A `url()` helper would
 * eventually be called from a template, and §7.5 forbids these files ever being
 * reachable without an authorisation check.
 */
class KycDocumentStore
{
    /**
     * Disk name. Kept off the default so a misconfigured `public` disk cannot
     * accidentally expose identity documents.
     */
    public const DISK = 'kyc';

    public function __construct(
        protected FilesystemFactory $filesystem,
    ) {}

    /**
     * Store an uploaded file against a submission.
     *
     * Validates against the administrator's rules for the type (§7.2) before
     * writing anything — an oversized or wrong-format file should never reach
     * the disk at all.
     */
    public function store(
        KycSubmission $submission,
        KycDocumentType $type,
        UploadedFile $file,
    ): KycDocument {
        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();

        if (! $type->accepts($mime, $size)) {
            throw new InvalidArgumentException(sprintf(
                'A %s of %d KB is not accepted for %s. Allowed: %s, up to %d KB.',
                $mime,
                (int) round($size / 1024),
                $type->name,
                implode(', ', $type->accepted_mime_types),
                $type->max_size_kb,
            ));
        }

        $contents = (string) file_get_contents($file->getRealPath());
        $checksum = hash('sha256', $contents);

        // Random name, not the original. An original filename can carry the
        // applicant's name, and a predictable path is a path someone can try.
        $path = sprintf(
            'kyc/%s/%s/%s',
            $submission->user_id,
            $submission->public_id,
            bin2hex(random_bytes(16)),
        );

        $this->disk()->put($path, encrypt($contents));

        return $submission->documents()->create([
            'kyc_document_type_id' => $type->id,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'checksum' => $checksum,
            'is_encrypted' => true,
        ]);
    }

    /**
     * Read a document's contents, recording who did so (§7.5).
     *
     * The access record is written **before** the file is returned. If recording
     * fails, nobody gets the file — an unrecorded read of someone's identity
     * documents is worse than a failed one.
     *
     * @param  'view'|'download'  $action
     */
    public function read(
        KycDocument $document,
        ?int $accessedBy,
        string $action = 'view',
        ?string $ip = null,
        ?string $userAgent = null,
    ): string {
        KycDocumentAccess::create([
            'kyc_document_id' => $document->id,
            'accessed_by' => $accessedBy,
            'action' => $action,
            'ip_address' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
        ]);

        $stored = (string) $this->filesystem->disk($document->disk)->get($document->path);

        $contents = $document->is_encrypted ? decrypt($stored) : $stored;

        return (string) $contents;
    }

    /**
     * Whether the stored file still matches what was uploaded.
     *
     * Used before a reviewer relies on a document — a corrupted or swapped file
     * should be caught rather than approved.
     */
    public function verifyIntegrity(KycDocument $document): bool
    {
        $stored = (string) $this->filesystem->disk($document->disk)->get($document->path);
        $contents = $document->is_encrypted ? decrypt($stored) : $stored;

        return hash_equals($document->checksum, hash('sha256', (string) $contents));
    }

    /**
     * Remove a stored file.
     *
     * Only for an abandoned draft. A document belonging to a reviewed submission
     * is evidence behind a decision (§7.3) and stays.
     */
    public function deleteDraftFile(KycDocument $document): void
    {
        $submission = $document->submission()->first();

        if ($submission === null || ! $submission->status->isEditable()) {
            throw new InvalidArgumentException(
                'Only a document on an editable draft submission can be removed.'
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
