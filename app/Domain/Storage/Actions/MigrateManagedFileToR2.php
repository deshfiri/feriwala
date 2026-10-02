<?php

namespace App\Domain\Storage\Actions;

use App\Domain\Storage\Data\MigrationOutcome;
use App\Domain\Storage\Data\MigrationSourceDefinition;
use App\Domain\Storage\R2StorageSettings;
use App\Integrations\Storage\R2Manager;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Move one already-stored row's bytes onto Cloudflare R2, verify the copy
 * byte-for-byte, and only then flip where it is read from
 * (beta-critical batch, Commit 5).
 *
 * The local original is never touched. A row's `disk` column flips to `r2`
 * only after the upload has been read back and checksummed against the
 * local bytes -- a dropped connection or a partial write fails the
 * verification and leaves the row pointed at its local copy, which is why
 * re-running the migrate command is always safe: a row not yet on `r2` is
 * picked up again next time, exactly as it was left.
 *
 * Works on whatever bytes are on disk, encrypted or not -- an already
 * app-level-encrypted KYC document is moved as the same ciphertext, never
 * decrypted here. R2 is never asked to do anything an existing local disk
 * wasn't already trusted to do.
 */
class MigrateManagedFileToR2
{
    public function __construct(
        protected R2Manager $r2,
        protected R2StorageSettings $r2Settings,
        protected FilesystemFactory $filesystem,
    ) {}

    public function migrateRow(Model $row, MigrationSourceDefinition $source): MigrationOutcome
    {
        $id = (int) $row->getKey();
        $disk = (string) $row->getAttribute($source->diskColumn);
        $path = (string) $row->getAttribute($source->pathColumn);

        if ($disk === 'r2') {
            return MigrationOutcome::alreadyOnR2($id);
        }

        $local = $this->filesystem->disk($disk);

        if (! $local->exists($path)) {
            return MigrationOutcome::missingLocalFile($id);
        }

        $bytes = (string) $local->get($path);
        $r2Disk = $this->r2->disk($this->r2Settings->toR2ManagerCredentials());

        // Never overwrite an object already there -- a resumed run may find
        // its own previous, already-correct upload.
        if (! $r2Disk->exists($path)) {
            $r2Disk->put($path, $bytes);
        }

        $remoteBytes = (string) $r2Disk->get($path);

        if (! hash_equals(hash('sha256', $bytes), hash('sha256', $remoteBytes))) {
            return MigrationOutcome::verificationFailed($id);
        }

        $row->setAttribute($source->diskColumn, 'r2')->save();

        return MigrationOutcome::migrated($id);
    }

    /**
     * Re-check a row already on `r2` against its own recorded size and
     * checksum (when the table tracks one) -- a post-hoc integrity sweep,
     * never a write.
     */
    public function verifyRow(Model $row, MigrationSourceDefinition $source): MigrationOutcome
    {
        $id = (int) $row->getKey();

        if ((string) $row->getAttribute($source->diskColumn) !== 'r2') {
            return MigrationOutcome::skippedNotOnR2($id);
        }

        $path = (string) $row->getAttribute($source->pathColumn);
        $r2Disk = $this->r2->disk($this->r2Settings->toR2ManagerCredentials());

        if (! $r2Disk->exists($path)) {
            return MigrationOutcome::missingRemoteFile($id);
        }

        $bytes = (string) $r2Disk->get($path);
        $expectedSize = (int) $row->getAttribute($source->sizeColumn);

        if (strlen($bytes) !== $expectedSize) {
            return MigrationOutcome::verificationFailed($id);
        }

        if ($source->checksumColumn !== null) {
            $expected = (string) $row->getAttribute($source->checksumColumn);

            if (! hash_equals($expected, hash('sha256', $bytes))) {
                return MigrationOutcome::verificationFailed($id);
            }
        }

        return MigrationOutcome::verified($id);
    }
}
