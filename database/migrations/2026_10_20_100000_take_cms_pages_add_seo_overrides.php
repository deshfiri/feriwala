<?php

use App\Domain\Cms\Actions\PublishPage;
use App\Domain\Cms\Support\PublishedPageReader;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A page's own SEO override (§34, Stage 7). Draft-side, like every other
 * `cms_pages` column: editing it never touches a published revision, and it
 * only reaches a visitor once {@see PublishPage}
 * snapshots it into a new one.
 *
 * Shape matches what {@see PublishedPageReader::seo()}
 * already expects from a revision's frozen `seo` snapshot: `title` and
 * `description` are `{en, bn}`, the rest are plain strings. Null (the
 * default) means "no override" — the reader's fallback chain reaches the
 * global `cms_seo_settings` default instead of a placeholder that would
 * outrank it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_pages', function (Blueprint $table) {
            $table->jsonb('seo_overrides')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cms_pages', function (Blueprint $table) {
            $table->dropColumn('seo_overrides');
        });
    }
};
