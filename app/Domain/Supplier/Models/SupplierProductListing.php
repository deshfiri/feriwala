<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Models\User;
use App\Support\References\ReferencePrefix;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Supplier Product Listing Request (D25, contract-equivalent §11/§12,
 * P13-11).
 *
 * A proposal. Creating or updating this row never writes to `products` —
 * §12 stays exactly as it is, Admin/Authorized-User-only. `connected_product_id`
 * is set only by the review action, once a decision has actually been made.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $supplier_id
 * @property int|null $lot_id
 * @property ListingStatus $status
 * @property string $product_name
 * @property string|null $description
 * @property int|null $category_id
 * @property string|null $category_suggestion
 * @property int|null $brand_id
 * @property string|null $brand_suggestion
 * @property string|null $supplier_note
 * @property array<int, array<string, mixed>>|null $images
 * @property array<int, array<string, mixed>>|null $documents
 * @property int|null $connected_product_id
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $reviewed_by
 * @property string|null $decision_note
 * @property-read Supplier $supplier
 * @property-read Category|null $category
 * @property-read Brand|null $brand
 * @property-read Product|null $connectedProduct
 * @property-read User|null $reviewedBy
 * @property-read Collection<int, SupplierProductListingItem> $items
 * @property-read Collection<int, SupplierProductListingStatusChange> $statusHistory
 */
class SupplierProductListing extends Model
{
    use HasPublicId, HasReference, HasStateMachine, RecordsStatusHistory;

    protected $guarded = [];

    protected $attributes = [
        'status' => ListingStatus::Draft->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'images' => 'array',
            'documents' => 'array',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function referencePrefix(): ReferencePrefix
    {
        return ReferencePrefix::SupplierListing;
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * The batch this product entry was drafted into, when it was created
     * through the lot workspace rather than the single-listing path.
     *
     * @return BelongsTo<SupplierProductListingLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(SupplierProductListingLot::class, 'lot_id');
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
     * @return BelongsTo<Product, $this>
     */
    public function connectedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'connected_product_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<SupplierProductListingItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierProductListingItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<SupplierProductListingStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(SupplierProductListingStatusChange::class)->orderBy('id');
    }

    /**
     * Whether this Supplier's own listing may still be edited by them.
     */
    public function isEditableBySupplier(): bool
    {
        return in_array($this->status, [ListingStatus::Draft, ListingStatus::CorrectionRequired], true);
    }
}
