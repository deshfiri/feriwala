<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global, per-locale SEO defaults (§4, §34) — organization schema, OG
 * defaults, robots rules. Deliberately its own table, not the general
 * `settings` key-value store: those rows are flat admin config (fees,
 * toggles, branding paths); this is structured, versioned-alongside-the-CMS
 * content, and grows a field at a time as SEO needs do, which a generic
 * key-value row does not suit.
 *
 * One row per locale. A page's own title/description/canonical/OG fields
 * (set per page, snapshotted into its revision) take precedence over these
 * when present; these are the fallback for anything a page does not
 * override, and the source for the organization-wide structured data every
 * page carries regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_seo_settings', function (Blueprint $table) {
            $table->id();
            $table->char('locale', 2)->unique();

            $table->string('default_title');
            $table->string('default_description', 500)->nullable();
            $table->string('default_og_image_path')->nullable();

            $table->string('organization_name');
            $table->string('organization_logo_path')->nullable();
            $table->string('organization_url')->nullable();

            $table->string('robots_default', 40)->default('index, follow');
            $table->string('twitter_handle')->nullable();

            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE cms_seo_settings
                ADD CONSTRAINT cms_seo_settings_locale_known CHECK (locale IN ('en', 'bn')),
                ADD CONSTRAINT cms_seo_settings_title_present CHECK (length(btrim(default_title)) > 0);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_seo_settings');
    }
};
