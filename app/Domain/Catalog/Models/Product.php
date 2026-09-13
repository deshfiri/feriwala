<?php

namespace App\Domain\Catalog\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasSlug;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product in the central catalogue (§11.1).
 *
 * Feriwala's record, never a partner's (§12). Business accounts reach products
 * only through eligibility-scoped queries, and nothing they can post writes to
 * this model.
 *
 * @property int $id
 * @property string $public_id
 * @property string $slug
 * @property string $sku
 * @property string|null $barcode
 * @property string $name
 * @property string|null $short_description
 * @property string|null $description
 * @property int $category_id
 * @property int|null $brand_id
 * @property string $currency_code
 * @property Money $base_cost_minor
 * @property Money $wholesale_price_minor
 * @property string $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Category $category
 * @property-read Brand|null $brand
 */
class Product extends Model
{
    /*
     * The same two public identifiers categories and brands have: the panel
     * addresses a product by public id, a partner storefront by slug, and the
     * public id wins the route binding because that is what the panel's routes
     * carry.
     */
    use HasPublicId, HasSlug {
        HasPublicId::getRouteKeyName insteadof HasSlug;
    }

    public const STATUS_DRAFT = 'draft';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_cost_minor' => MoneyCast::class,
            'wholesale_price_minor' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
