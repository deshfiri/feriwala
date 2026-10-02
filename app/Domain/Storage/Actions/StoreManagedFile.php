<?php

namespace App\Domain\Storage\Actions;

use App\Domain\Storage\Enums\StorageVisibility;
use App\Domain\Storage\Exceptions\UnacceptableFile;
use App\Domain\Storage\ManagedStorage;
use App\Domain\Storage\Models\StoredFile;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Write one upload through the shared managed-storage abstraction
 * (beta-critical batch, Commit 4): validated from its own bytes, given a
 * collision-safe name, and recorded with the metadata every managed file
 * should carry -- mime type, size, a checksum, and which disk it actually
 * landed on.
 *
 * Every caller supplies its own allow-list and size cap rather than this
 * action guessing a default -- a browser icon and a KYC scan have nothing in
 * common to default to.
 */
class StoreManagedFile
{
    public function __construct(
        protected ManagedStorage $storage,
    ) {}

    /**
     * @param  list<string>  $allowedMimeTypes  read from the file's own bytes, never the browser's claim
     * @param  array<string, string>|null  $extensionsByMime  overrides the guessed extension per accepted
     *                                                        MIME type, for a caller whose accepted types
     *                                                        need an exact extension (e.g. a favicon's
     *                                                        `image/x-icon` -> `ico`) rather than whatever
     *                                                        the generic MIME-to-extension guesser returns
     *
     * @throws UnacceptableFile
     */
    public function handle(
        UploadedFile $file,
        string $purpose,
        StorageVisibility $visibility,
        array $allowedMimeTypes,
        int $maxBytes,
        bool $encrypt = false,
        ?Model $fileable = null,
        ?int $createdBy = null,
        ?CarbonInterface $retainUntil = null,
        ?array $extensionsByMime = null,
    ): StoredFile {
        $mime = (string) $file->getMimeType();

        if (! in_array($mime, $allowedMimeTypes, true)) {
            throw UnacceptableFile::mimeNotAccepted($mime);
        }

        if ((int) $file->getSize() > $maxBytes) {
            throw UnacceptableFile::tooLarge($maxBytes);
        }

        $bytes = (string) file_get_contents((string) $file->getRealPath());
        $checksum = hash('sha256', $bytes);
        $extension = ($extensionsByMime[$mime] ?? $file->extension()) ?: 'bin';
        $path = sprintf('%s/%s.%s', $purpose, bin2hex(random_bytes(16)), $extension);

        $disk = $this->storage->diskFor($visibility);
        $disk->put($path, $encrypt ? encrypt($bytes) : $bytes);

        return StoredFile::query()->create([
            'purpose' => $purpose,
            'fileable_type' => $fileable?->getMorphClass(),
            'fileable_id' => $fileable?->getKey(),
            'disk' => $this->storage->diskNameFor($visibility),
            'path' => $path,
            'visibility' => $visibility,
            'mime_type' => $mime,
            'size_bytes' => strlen($bytes),
            'checksum' => $checksum,
            'is_encrypted' => $encrypt,
            'retain_until' => $retainUntil,
            'created_by' => $createdBy,
        ]);
    }
}
