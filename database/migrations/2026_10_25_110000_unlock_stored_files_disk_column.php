<?php

use App\Domain\Storage\Actions\MigrateManagedFileToR2;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `stored_files.disk` must change exactly once, from a local disk to `r2`,
 * when {@see MigrateManagedFileToR2} moves a
 * file's bytes onto Cloudflare R2 (beta-critical batch, Commit 5) --
 * Commit 4's own locked-columns trigger included `disk` by mistake, written
 * before this migration command existed to need it. `public_id`, `path`,
 * and the polymorphic owner stay locked; only `disk` is released.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stored_files_locked_columns ON stored_files;

            CREATE TRIGGER stored_files_locked_columns
                BEFORE UPDATE OF public_id, path, fileable_type, fileable_id ON stored_files
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'path', 'fileable_type', 'fileable_id'
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stored_files_locked_columns ON stored_files;

            CREATE TRIGGER stored_files_locked_columns
                BEFORE UPDATE OF public_id, disk, path, fileable_type, fileable_id ON stored_files
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'disk', 'path', 'fileable_type', 'fileable_id'
                );
        SQL);
    }
};
