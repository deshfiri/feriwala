<?php

use App\Domain\Storage\Actions\StoreManagedFile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shared managed-storage metadata table (beta-critical batch, Commit 4).
 *
 * One row per file written through {@see StoreManagedFile},
 * whichever disk actually holds the bytes -- the local `public`/`private`
 * disks today, Cloudflare R2 once it is switched on. `disk` and `path`
 * together are where the bytes live right now; `visibility` is the property
 * that decides which disk a future write or read targets, so it survives a
 * later switch to R2 unchanged.
 *
 * `fileable_type`/`fileable_id` are a nullable polymorphic owner -- nullable
 * because a handful of real surfaces (the platform's own branding logo, a
 * storefront's logo/banner) have no natural owning model, only a `purpose`
 * that is unique by construction (one logo, one favicon, one logo+banner per
 * website row that already exists elsewhere).
 *
 * This table does not replace `product_media`/`supplier_listing_media`/
 * `kyc_documents`/`supplier_kyc_documents` -- each already carries its own
 * domain-specific columns and compliance history (§7.5's KYC access audit in
 * particular) and is left exactly as built. It is the home for surfaces that
 * previously tracked no metadata at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->string('purpose', 60);

            $table->string('fileable_type', 191)->nullable();
            $table->unsignedBigInteger('fileable_id')->nullable();

            $table->string('disk', 20);
            $table->string('path', 500)->unique();
            $table->string('visibility', 10);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64)->nullable();
            $table->boolean('is_encrypted')->default(false);

            $table->timestamp('retain_until')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['fileable_type', 'fileable_id']);
            $table->index('purpose');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE stored_files
                ADD CONSTRAINT stored_files_visibility_known CHECK (visibility IN ('public', 'private')),
                ADD CONSTRAINT stored_files_size_positive CHECK (size_bytes > 0),
                ADD CONSTRAINT stored_files_checksum_shape CHECK (checksum IS NULL OR length(checksum) = 64);

            CREATE TRIGGER stored_files_locked_columns
                BEFORE UPDATE OF public_id, disk, path, fileable_type, fileable_id ON stored_files
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'disk', 'path', 'fileable_type', 'fileable_id'
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stored_files_locked_columns ON stored_files;
        SQL);

        Schema::dropIfExists('stored_files');
    }
};
