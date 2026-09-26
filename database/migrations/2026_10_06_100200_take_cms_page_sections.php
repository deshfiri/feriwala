<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A page's live, editable sections (§4, §34). Ordinary mutable rows — this is
 * the draft a Stage 7 admin UI edits directly. None of it is public: the
 * published reader never queries this table, only the frozen JSON a publish
 * copied into `cms_page_revisions`, so an in-progress edit here can never
 * leak to a visitor mid-edit.
 *
 * `content` is validated PHP-side against a per-`kind` schema
 * (App\Domain\Cms\Support\SectionContentValidator) before it is ever
 * written — the jsonb column itself accepts anything, the same way `status`
 * columns elsewhere accept any string and the enum is what actually
 * constrains it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_page_sections', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('cms_page_id')->constrained()->restrictOnDelete();

            // Stable across a reorder — a section's own identity, referenced
            // by the section-order array rather than re-derived from position.
            $table->string('section_key', 64);

            $table->string('kind', 32);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('visible_on_desktop')->default(true);
            $table->boolean('visible_on_mobile')->default(true);
            $table->string('variant', 32)->nullable();

            $table->jsonb('content');
            $table->jsonb('color_overrides')->nullable();

            $table->timestamps();

            $table->unique(['cms_page_id', 'section_key']);
            $table->index(['cms_page_id', 'sort_order']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE cms_page_sections
                ADD CONSTRAINT cms_page_sections_key_present CHECK (length(btrim(section_key)) > 0);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_page_sections');
    }
};
