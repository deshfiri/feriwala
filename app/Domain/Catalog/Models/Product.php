<?php

namespace App\Domain\Catalog\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasSlug;
use App\Concerns\HasStateMachine;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Data\ProductLogistics;
use App\Domain\Catalog\Enums\AccountScope;
use App\Domain\Catalog\Enums\ItemCondition;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Package\Models\Package;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
 * @property Money $base_cost
 * @property Money $wholesale_price
 * @property int $min_order_quantity
 * @property int|null $max_order_quantity
 * @property Money|null $suggested_selling_price
 * @property Money|null $minimum_selling_price
 * @property Money|null $maximum_selling_price
 * @property ProductStatus $status
 * @property PackageScope $package_scope
 * @property AccountScope $account_scope
 * @property ProductStatus $dropshipping_status
 * @property ProductStatus $wholesale_status
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $meta_keywords
 * @property string|null $mpn
 * @property ItemCondition $item_condition
 * @property int|null $social_media_id
 * @property-read ProductMedia|null $socialImage
 * @property int|null $net_weight_grams
 * @property int|null $shipping_weight_grams
 * @property string|null $length_cm
 * @property string|null $width_cm
 * @property string|null $height_cm
 * @property bool $ships_by_box
 * @property int|null $pieces_per_box
 * @property int|null $box_weight_grams
 * @property string|null $box_length_cm
 * @property string|null $box_width_cm
 * @property string|null $box_height_cm
 * @property bool $is_fragile
 * @property bool $is_featured
 * @property CarbonImmutable|null $featured_at
 * @property-read bool|null $price_tiers_exists
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property int|null $deleted_by
 * @property string|null $deletion_reason
 * @property-read Category $category
 * @property-read Brand|null $brand
 * @property-read Collection<int, ProductVariant> $variants
 * @property-read Collection<int, ProductMedia> $media
 * @property-read User|null $deleter
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

    /*
     * `status` moves only through `transitionTo()`, which checks the move against
     * {@see ProductStatus}. The column's CHECK keeps it to the seven lifecycle
     * statuses whatever writes it.
     */
    use HasStateMachine;

    /*
     * Trash (urgent product-management fix). A trashed product disappears from
     * every catalogue, cart, storefront and allocation lookup immediately —
     * every one of them reads through `Product::query()`, so this one trait
     * scopes all of them at once. Nothing it names (status history, stock,
     * supplier offers, sourcing, orders) is touched; only this row is marked.
     */
    use SoftDeletes;

    /**
     * The column defaults, stated on the model as well as in the table.
     *
     * A product created in this request is otherwise missing every attribute
     * the database filled in, and a rule that reads one — eligibility asking for
     * `package_scope` — sees null and answers as if the rule were absent. The
     * least permissive defaults have to hold in memory, not only after a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency_code' => 'BDT',
        'base_cost' => '0.00',
        'wholesale_price' => '0.00',
        'min_order_quantity' => 1,
        'status' => 'draft',
        'package_scope' => 'selected',
        'account_scope' => 'any',
        'dropshipping_status' => 'dropshipping_disabled',
        'wholesale_status' => 'wholesale_disabled',
        'is_featured' => false,
        'item_condition' => 'new',
        'ships_by_box' => false,
        'is_fragile' => false,
    ];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_cost' => MoneyCast::class,
            'wholesale_price' => MoneyCast::class,
            'min_order_quantity' => 'integer',
            'max_order_quantity' => 'integer',
            'suggested_selling_price' => MoneyCast::class,
            'minimum_selling_price' => MoneyCast::class,
            'maximum_selling_price' => MoneyCast::class,
            'status' => ProductStatus::class,
            'package_scope' => PackageScope::class,
            'account_scope' => AccountScope::class,
            'dropshipping_status' => ProductStatus::class,
            'wholesale_status' => ProductStatus::class,
            'is_featured' => 'boolean',
            'featured_at' => 'immutable_datetime',
            'item_condition' => ItemCondition::class,
            'net_weight_grams' => 'integer',
            'shipping_weight_grams' => 'integer',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'ships_by_box' => 'boolean',
            'pieces_per_box' => 'integer',
            'box_weight_grams' => 'integer',
            'box_length_cm' => 'decimal:2',
            'box_width_cm' => 'decimal:2',
            'box_height_cm' => 'decimal:2',
            'is_fragile' => 'boolean',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * This product's status on one sales channel.
     */
    public function channelStatus(SalesChannel $channel): ProductStatus
    {
        $status = $this->getAttribute($channel->column());

        return $status instanceof ProductStatus ? $status : $channel->disabled();
    }

    /**
     * Whether the product is switched on for this channel. Not the same as being
     * offered on it — that also needs the product active and the partner
     * eligible, which {@see ProductEligibility} decides.
     */
    public function sellsThrough(SalesChannel $channel): bool
    {
        return $this->channelStatus($channel) === $channel->enabled();
    }

    /**
     * Whether one order may carry this many units (§11.1, §14).
     *
     * What the cart asks before it prices anything. A quantity outside the
     * bounds is refused rather than clamped: silently changing how many units
     * somebody ordered is changing their order.
     */
    public function acceptsQuantity(int $quantity): bool
    {
        if ($quantity < $this->min_order_quantity) {
            return false;
        }

        return $this->max_order_quantity === null || $quantity <= $this->max_order_quantity;
    }

    /**
     * This product's own logistics figures (beta-critical batch, Commit 1).
     */
    public function logistics(): ProductLogistics
    {
        return ProductLogistics::forProduct($this);
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

    /**
     * The packages this product is offered to, when its scope is "selected".
     *
     * @return BelongsToMany<Package, $this>
     */
    public function eligiblePackages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'product_package_eligibility')
            ->withPivot('created_at');
    }

    /**
     * The business accounts this product is restricted to, when its account
     * scope is "selected".
     *
     * @return BelongsToMany<BusinessAccount, $this>
     */
    public function eligibleAccounts(): BelongsToMany
    {
        return $this->belongsToMany(BusinessAccount::class, 'product_user_eligibility')
            ->withPivot('created_at');
    }

    /**
     * The image partner storefronts use when the page is shared (§34.3).
     *
     * @return BelongsTo<ProductMedia, $this>
     */
    public function socialImage(): BelongsTo
    {
        return $this->belongsTo(ProductMedia::class, 'social_media_id');
    }

    /**
     * Who sent this product to Trash, for the Trash screen.
     *
     * @return BelongsTo<User, $this>
     */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * The products this one recommends, in the order they are shown.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function relatedProducts(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_related', 'product_id', 'related_product_id')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /**
     * All quantity tiers, the product's own and its variations'.
     *
     * @return HasMany<ProductPriceTier, $this>
     */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(ProductPriceTier::class);
    }

    /**
     * Every status move, newest first.
     *
     * @return HasMany<ProductStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(ProductStatusChange::class)->orderByDesc('id');
    }

    /**
     * Images and videos, in the order a storefront shows them.
     *
     * @return HasMany<ProductMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Updates published for this product, newest first.
     *
     * @return HasMany<ProductContent, $this>
     */
    public function contents(): HasMany
    {
        return $this->hasMany(ProductContent::class)->orderByDesc('published_at');
    }
}
