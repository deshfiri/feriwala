<?php

use App\Domain\Cms\Actions\DeleteCmsMedia;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 7 addendum: the global Open Graph image and organization logo
 * become CMS media references (a `cms_media.public_id`) instead of a free
 * storage path — the same protection every section's own media field gets,
 * so an image cannot be physically deleted out from under the SEO defaults
 * and no internal disk path is ever exposed to the frontend. No FK
 * constraint: {@see DeleteCmsMedia} checks every
 * reference itself, the same way it already checks revisions and sections,
 * so the refusal carries a clear message rather than a bare constraint
 * violation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_seo_settings', function (Blueprint $table) {
            $table->renameColumn('default_og_image_path', 'default_og_image_id');
            $table->renameColumn('organization_logo_path', 'organization_logo_id');
        });
    }

    public function down(): void
    {
        Schema::table('cms_seo_settings', function (Blueprint $table) {
            $table->renameColumn('default_og_image_id', 'default_og_image_path');
            $table->renameColumn('organization_logo_id', 'organization_logo_path');
        });
    }
};
