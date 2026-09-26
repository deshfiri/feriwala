<?php

namespace App\Domain\Cms\Models;

use App\Concerns\HasPublicId;
use App\Domain\Cms\Enums\MenuLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property MenuLocation $location
 */
class Menu extends Model
{
    use HasPublicId;

    protected $table = 'cms_menus';

    protected $fillable = ['location'];

    protected function casts(): array
    {
        return ['location' => MenuLocation::class];
    }

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'cms_menu_id')
            ->whereNull('parent_id')
            ->orderBy('sort_order');
    }

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function allItems(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'cms_menu_id')->orderBy('sort_order');
    }
}
