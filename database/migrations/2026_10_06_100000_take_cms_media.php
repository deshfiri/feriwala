<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CMS media library (§4, §34; requirements/02-data-model.md's CMS table
 * list). No generic media system exists elsewhere in the app — every other
 * domain uses its own dedicated table (`product_media`, `kyc_documents`); this
 * one is the CMS's own, following the same house convention rather than
 * retrofitting either of those.
 *
 * The storage path is random, never the original filename — a filename a
 * visitor typed is not something the disk should trust or expose back
 * (mirrors ProductMediaStore's own reasoning). Alt text is required in both
 * locales at the application layer for any image actually placed on a
 * published page; the column stays nullable so a freshly uploaded, not-yet-
 * placed asset can exist before its alt text is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_media', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->string('disk', 32)->default('cms-media');
            $table->string('path');
            $table->string('original_filename');

            // Sniffed from the file's own bytes, never trusted from the
            // browser's Content-Type header (D26-adjacent discipline: never
            // trust what the client claims about its own upload).
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->string('alt_text_en')->nullable();
            $table->string('alt_text_bn')->nullable();
            $table->string('attribution')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE cms_media
                ADD CONSTRAINT cms_media_size_positive CHECK (size_bytes > 0),
                ADD CONSTRAINT cms_media_path_present CHECK (length(btrim(path)) > 0);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_media');
    }
};
