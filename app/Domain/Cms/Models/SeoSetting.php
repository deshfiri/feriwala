<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global, per-locale SEO defaults (§4, §34). One row per locale — see the
 * migration's own doc comment for why this is not the general `settings`
 * table.
 *
 * `default_og_image_id`/`organization_logo_id` hold a {@see Media}
 * `public_id`, resolved to a real URL through {@see ogImageUrl()}/
 * {@see organizationLogoUrl()} rather than ever being read directly — never
 * a storage path, and never null-unsafe if the reference has gone stale.
 *
 * @property int $id
 * @property string $locale
 * @property string $default_title
 * @property string|null $default_description
 * @property string|null $default_og_image_id
 * @property string $organization_name
 * @property string|null $organization_logo_id
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
        'default_og_image_id',
        'organization_name',
        'organization_logo_id',
        'organization_url',
        'robots_default',
        'twitter_handle',
    ];

    public function ogImageUrl(): ?string
    {
        return $this->mediaUrl($this->default_og_image_id);
    }

    public function organizationLogoUrl(): ?string
    {
        return $this->mediaUrl($this->organization_logo_id);
    }

    protected function mediaUrl(?string $mediaPublicId): ?string
    {
        if ($mediaPublicId === null) {
            return null;
        }

        return Media::query()->wherePublicId($mediaPublicId)->first()?->url();
    }
}
