<?php

namespace App\Domain\Website\Models;

use App\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A partner's own arrangement of their shop (§15).
 *
 * Not the central catalogue's categories — those are Feriwala's and a partner
 * cannot write them (§11.3, §12). These are how one storefront groups what it
 * sells: "Eid collection", "Under 500 taka", whatever the shop wants.
 *
 * Flat on purpose. §15 asks for placement and display order, not a tree, and a
 * second hierarchy that only one shop can see is a thing to maintain for no
 * gain.
 *
 * @property int $id
 * @property string $public_id
 * @property int $website_id
 * @property string $name
 * @property string $slug
 * @property int $position
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Website $website
 * @property-read Collection<int, WebsiteProduct> $products
 */
class WebsiteCategory extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * @return HasMany<WebsiteProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(WebsiteProduct::class);
    }

    /**
     * In the order the shop arranged them.
     *
     * @param  Builder<WebsiteCategory>  $query
     * @return Builder<WebsiteCategory>
     */
    public function scopeArranged(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
