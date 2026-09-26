<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CMS pages (§4, §34). A page's own row is deliberately thin: everything a
 * visitor actually reads — sections, SEO, menu snapshot — lives in whichever
 * `cms_page_revisions` row `current_published_revision_id` points at. This
 * row only tracks the page's identity and its publication state; editing
 * never touches a published revision (§34.1's "published revisions must
 * never be silently edited").
 *
 * `current_published_revision_id` is added by the next migration, once
 * `cms_page_revisions` exists to reference — the two tables refer to each
 * other, so one of the two foreign keys has to come second.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // Stable across a rename — never appears in a public URL for the
            // 'landing' page type, which is served at '/'.
            $table->string('slug')->unique();
            $table->string('page_type', 32)->default('landing');
            $table->char('default_locale', 2)->default('en');

            // A page may have a draft ahead of what is published, or none —
            // publishing snapshots the live `cms_page_sections` rows into a
            // new `cms_page_revisions` row and repoints this column.
            $table->string('publication_state', 16)->default('draft');
            $table->timestamp('scheduled_publish_at')->nullable();
            $table->timestamp('scheduled_unpublish_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE cms_pages
                ADD CONSTRAINT cms_pages_publication_state_known CHECK (
                    publication_state IN ('draft', 'scheduled', 'published', 'unpublished')
                ),
                ADD CONSTRAINT cms_pages_slug_present CHECK (length(btrim(slug)) > 0),
                ADD CONSTRAINT cms_pages_locale_known CHECK (default_locale IN ('en', 'bn'));
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_pages');
    }
};
