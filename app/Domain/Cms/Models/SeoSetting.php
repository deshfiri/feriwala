<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global, per-locale SEO defaults (§4, §34). One row per locale — see the
 * migration's own doc comment for why this is not the general `settings`
 * table.
 *
 * @property int $id
 * @property string $locale
 * @property string $default_title
 * @property string|null $default_description
 * @property string|null $default_og_image_path
 * @property string $organization_name
 * @property string|null $organization_logo_path
 * @property string|null $organization_url
 * @property string $robots_default
 * @property string|null $twitter_handle
 */
class SeoSetting extends Model
{
    protected $table = 'cms_seo_settings';

    protected $fillable = [
        'locale',
        'default_title',
        'default_description',
        'default_og_image_path',
        'organization_name',
        'organization_logo_path',
        'organization_url',
        'robots_default',
        'twitter_handle',
    ];
}
