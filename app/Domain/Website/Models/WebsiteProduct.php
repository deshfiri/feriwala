<?php

namespace App\Domain\Website\Models;

use App\Casts\MoneyCast;
use App\Casts\UtcInstantCast;
use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Models\Product;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One central product, selected for one storefront (§15, §15.1, P5-2).
 *
 * A **selection**, never a copy. What the product is — its name, images,
 * variants, stock — stays in the catalogue, where only Feriwala writes it
 * (§12). What lives here is only what §15.1 lets a partner decide: where it
 * sits in their shop, in what order, whether it is featured, what they charge,
 * and the words they sell it with.
 *
 * The website, the account and the product it points at are locked once
 * written: moving a selection between shops would carry a price agreed for one
 * storefront onto another.
 *
 * @property int $id
 * @property string $public_id
 * @property int $website_id
 * @property int $business_account_id
 * @property int $product_id
 * @property int|null $website_category_id
 * @property WebsiteProductStatus $status
 * @property bool $is_featured
 * @property bool $auto_listed on sale because the shop lists the whole catalogue, not because its owner chose it
 * @property int $display_order
 * @property string $currency_code
 * @property Money|null $price
 * @property Money|null $promotional_price
 * @property CarbonImmutable|null $promotion_starts_at
 * @property CarbonImmutable|null $promotion_ends_at
 * @property bool $promotion_live the promotion's state as last announced (C4)
 * @property string|null $promo_title
 * @property string|null $marketing_description
 * @property WebsiteSyncStatus $sync_status
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $sync_error
 * @property array<int, array{sku: string, in_stock: bool, quantity: int}>|null $synced_availability
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $unpublished_at
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Website $website
 * @property-read BusinessAccount $businessAccount
 * @property-read Product $product
 * @property-read WebsiteCategory|null $websiteCategory
 * @property-read User|null $createdBy
 */
class WebsiteProduct extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebsiteProductStatus::class,
            'sync_status' => WebsiteSyncStatus::class,
            'is_featured' => 'boolean',
            'auto_listed' => 'boolean',
            'price' => MoneyCast::class,
            'promotional_price' => MoneyCast::class,
            'promotion_starts_at' => UtcInstantCast::class,
            'promotion_ends_at' => UtcInstantCast::class,
            'promotion_live' => 'boolean',
            'last_synced_at' => 'immutable_datetime',
            'synced_availability' => 'array',
            'published_at' => 'immutable_datetime',
            'unpublished_at' => 'immutable_datetime',
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
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<WebsiteCategory, $this>
     */
    public function websiteCategory(): BelongsTo
    {
        return $this->belongsTo(WebsiteCategory::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What a customer is charged at `$at` (now by default): the promotion while
     * it applies, the regular price otherwise.
     *
     * The one rule for a selection's price. Order pricing, the storefront
     * payload and every webhook read it through here, and the catalogue's SQL
     * ({@see self::effectivePriceSql()}) states the same rule for the database.
     */
    public function sellingPrice(?DateTimeInterface $at = null): ?Money
    {
        return $this->promotionApplies($at) ? $this->promotional_price : $this->price;
    }

    /**
     * Whether the promotional price applies at `$at` (C4).
     *
     * With no window, whenever it is set — the behaviour before windows existed.
     * With one, from its start (inclusive) until its end (exclusive).
     */
    public function promotionApplies(?DateTimeInterface $at = null): bool
    {
        if ($this->promotional_price === null) {
            return false;
        }

        $at = CarbonImmutable::instance($at ?? CarbonImmutable::now());

        return ($this->promotion_starts_at === null || $this->promotion_starts_at->lessThanOrEqualTo($at))
            && ($this->promotion_ends_at === null || $this->promotion_ends_at->greaterThan($at));
    }

    /**
     * {@see self::promotionApplies()} as a SQL condition over `website_products`.
     * The instant is a binding; the SQL is literal.
     *
     * @return array{0: literal-string, 1: list<string>} the condition and its bindings
     */
    public static function promotionAppliesSql(DateTimeInterface $at): array
    {
        $instant = UtcInstantCast::toDatabase($at);

        return [
            '(website_products.promotional_price IS NOT NULL'
            .' AND (website_products.promotion_starts_at IS NULL OR website_products.promotion_starts_at <= ?::timestamptz)'
            .' AND (website_products.promotion_ends_at IS NULL OR website_products.promotion_ends_at > ?::timestamptz))',
            [$instant, $instant],
        ];
    }

    /**
     * {@see self::sellingPrice()} as a SQL expression over `website_products`.
     *
     * @return array{0: literal-string, 1: list<string>} the expression and its bindings
     */
    public static function effectivePriceSql(DateTimeInterface $at): array
    {
        [$applies, $bindings] = self::promotionAppliesSql($at);

        return [
            "(CASE WHEN {$applies} THEN website_products.promotional_price ELSE website_products.price END)",
            $bindings,
        ];
    }

    /**
     * Selections that are on sale.
     *
     * @param  Builder<WebsiteProduct>  $query
     * @return Builder<WebsiteProduct>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', WebsiteProductStatus::Published->value);
    }

    /**
     * Selections the owner chose, as opposed to those listed by default.
     *
     * @param  Builder<WebsiteProduct>  $query
     * @return Builder<WebsiteProduct>
     */
    public function scopeChosen(Builder $query): Builder
    {
        return $query->where('auto_listed', false);
    }

    /**
     * One account's selections, across every storefront it runs.
     *
     * @param  Builder<WebsiteProduct>  $query
     * @return Builder<WebsiteProduct>
     */
    public function scopeForAccount(Builder $query, BusinessAccount $account): Builder
    {
        return $query->where('business_account_id', $account->id);
    }
}
