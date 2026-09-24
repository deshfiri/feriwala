<?php

namespace App\Domain\Website\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\Product;
use App\Domain\Package\Models\Package;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The administrator's half of §15.1 (P5-5).
 *
 * Whether a partner may set their own price at all, the floor and the ceiling,
 * the price Feriwala suggests, how far above the floor a partner may go, and
 * which of §15.1's settings are locked.
 *
 * Scoped the way every other rule in the platform is: a rule naming this
 * product **and** this package wins, then one naming the product, then one
 * naming the package, then the global rule. Specificity beats recency, so a
 * newer blanket rule cannot quietly override a decision made for one product.
 *
 * Opened and closed rather than edited, like fee and tax rules: a price bound
 * that changed in place would rewrite what a partner was allowed to charge
 * last month.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $product_id
 * @property int|null $package_id
 * @property bool $allows_user_pricing
 * @property string $currency_code
 * @property Money|null $min_price
 * @property Money|null $max_price
 * @property Money|null $suggested_price
 * @property int|null $max_margin_percent
 * @property array<int, string> $locked_fields
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Product|null $product
 * @property-read Package|null $package
 * @property-read User|null $createdBy
 */
class WebsiteProductPriceRule extends Model
{
    use HasPublicId;

    /**
     * The §15.1 settings a rule may lock.
     *
     * A closed list, because a locked field nobody validates is a setting that
     * silently stays editable.
     *
     * @var array<int, string>
     */
    public const LOCKABLE_FIELDS = [
        'price',
        'promotional_price',
        'promo_title',
        'marketing_description',
        'website_category',
        'display_order',
        'is_featured',
    ];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allows_user_pricing' => 'boolean',
            'min_price' => MoneyCast::class,
            'max_price' => MoneyCast::class,
            'suggested_price' => MoneyCast::class,
            'locked_fields' => 'array',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Rules in force at a moment.
     *
     * @param  Builder<WebsiteProductPriceRule>  $query
     * @return Builder<WebsiteProductPriceRule>
     */
    public function scopeEffectiveAt(Builder $query, CarbonImmutable $at): Builder
    {
        return $query
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $at));
    }
}
