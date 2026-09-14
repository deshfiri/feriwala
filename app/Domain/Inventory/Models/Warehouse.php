<?php

namespace App\Domain\Inventory\Models;

use App\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place central stock is held (§19, D15).
 *
 * Its code is fixed once written — it is printed on labels and pick lists — and
 * exactly one active warehouse is the default, which D15 tries first.
 *
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property string|null $address
 * @property bool $is_active
 * @property bool $is_default
 * @property int $priority
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Warehouse extends Model
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
            'is_default' => 'boolean',
            'priority' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<StockItem, $this>
     */
    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    /**
     * @param  Builder<Warehouse>  $query
     * @return Builder<Warehouse>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * D15's order: the default first, then by configured priority.
     *
     * @param  Builder<Warehouse>  $query
     * @return Builder<Warehouse>
     */
    public function scopeInPriorityOrder(Builder $query): Builder
    {
        return $query->orderByDesc('is_default')->orderBy('priority')->orderBy('id');
    }
}
