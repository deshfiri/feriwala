<?php

namespace App\Domain\Sourcing\Models;

use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\Product;
use App\Domain\Sourcing\Enums\SourcingMappingStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff-made record that one Supplier actually has one Product.
 *
 * Carries no price: rates, stock and approval live in the Supplier listing
 * system. Never deleted — removing it records who, when and why.
 *
 * @property int $id
 * @property string $public_id
 * @property int $product_id
 * @property int $supplier_id
 * @property SourcingMappingStatus $status
 * @property string|null $supplier_reference
 * @property int $assigned_by
 * @property CarbonImmutable $assigned_at
 * @property string|null $assign_reason
 * @property int|null $removed_by
 * @property CarbonImmutable|null $removed_at
 * @property string|null $removal_reason
 * @property-read Product $product
 * @property-read Supplier $supplier
 * @property-read User $assigner
 */
class ProductSupplierAssignment extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SourcingMappingStatus::class,
            'assigned_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @param  Builder<ProductSupplierAssignment>  $query
     * @return Builder<ProductSupplierAssignment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SourcingMappingStatus::Active);
    }
}
