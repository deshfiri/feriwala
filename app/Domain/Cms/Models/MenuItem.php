<?php

namespace App\Domain\Cms\Models;

use App\Concerns\HasPublicId;
use App\Domain\Cms\Rules\SafeMenuUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Route;

/**
 * One entry in a public-site menu (§4, §34). Points at exactly one
 * destination — an internal named route, resolved fresh on every read so a
 * later route rename cannot leave a menu item pointing at a 404, or a safe
 * external URL, validated by {@see SafeMenuUrl} at
 * write time.
 *
 * @property int $id
 * @property string $public_id
 * @property int $cms_menu_id
 * @property int|null $parent_id
 * @property string $label_en
 * @property string|null $label_bn
 * @property string|null $route_name
 * @property string|null $external_url
 * @property int $sort_order
 * @property bool $is_enabled
 * @property string $link_target
 */
class MenuItem extends Model
{
    use HasPublicId;

    protected $table = 'cms_menu_items';

    protected $fillable = [
        'cms_menu_id',
        'parent_id',
        'label_en',
        'label_bn',
        'route_name',
        'external_url',
        'sort_order',
        'is_enabled',
        'link_target',
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'cms_menu_id');
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function label(string $locale): string
    {
        return $locale === 'bn' && filled($this->label_bn) ? $this->label_bn : $this->label_en;
    }

    /**
     * The href a menu item resolves to. An internal route is resolved fresh
     * here rather than cached, so a route rename shows up immediately
     * instead of surviving in a stale snapshot.
     */
    public function href(): ?string
    {
        if ($this->route_name !== null) {
            return Route::has($this->route_name)
                ? route($this->route_name, absolute: false)
                : null;
        }

        return $this->external_url;
    }
}
