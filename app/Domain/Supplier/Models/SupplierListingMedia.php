<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Supplier\SupplierListingMediaStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image on a Supplier's product entry, or on one of its variations
 * (Supplier Bulk Product Listing batch) -- shaped like
 * {@see ProductMedia}, stored on the private
 * `supplier-media` disk through {@see SupplierListingMediaStore}
 * rather than the public one, since this is a pre-approval proposal, not a
 * published storefront asset.
 *
 * Immutable at the database level once the parent listing leaves
 * `draft`/`correction_required` (the `feriwala_supplier_listing_media_locked_after_submission`
 * trigger) -- the row itself is what staff reviewed, not something a later
 * Supplier edit can quietly change out from under a decision already made.
 *
 * @property int $id
 * @property string $public_id
 * @property int $supplier_product_listing_id
 * @property int|null $supplier_product_listing_item_id
 * @property string $role
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string $alt_text
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property-read SupplierProductListing $listing
 * @property-read SupplierProductListingItem|null $item
 */
class SupplierListingMedia extends Model
{
    use HasPublicId;

    public const ROLE_PRIMARY = 'primary';

    public const ROLE_GALLERY = 'gallery';

    protected $table = 'supplier_listing_media';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SupplierProductListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(SupplierProductListing::class, 'supplier_product_listing_id');
    }

    /**
     * @return BelongsTo<SupplierProductListingItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SupplierProductListingItem::class, 'supplier_product_listing_item_id');
    }

    public function isPrimary(): bool
    {
        return $this->role === self::ROLE_PRIMARY;
    }
}
