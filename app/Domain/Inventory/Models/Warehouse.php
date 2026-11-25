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
 * @property string|null $courier_contact_name the person a courier asks for on a return
 * @property string|null $courier_phone the number approved for couriers to call
 * @property string|null $return_address_line
 * @property string|null $return_area area, upazila or thana
 * @property string|null $return_district
 * @property string|null $return_postcode
 * @property bool $usable_for_courier_labels staff have approved this warehouse as a label's return address
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
     * The database's own defaults, so a warehouse created in code knows it is
     * active without being read back first.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'is_default' => false,
        'priority' => 0,
        'usable_for_courier_labels' => false,
    ];

    /**
     * Whether a shipping label may name this warehouse as where the parcel
     * came from and goes back to: switched on by staff, active, and every
     * required part of the return details present. Never a partial address.
     */
    public function isUsableForCourierLabels(): bool
    {
        return $this->is_active
            && $this->usable_for_courier_labels
            && $this->filled($this->courier_contact_name)
            && $this->filled($this->courier_phone)
            && $this->filled($this->return_address_line)
            && $this->filled($this->return_district);
    }

    /**
     * The return address as a label freezes it.
     *
     * @return array{line: string|null, area: string|null, district: string|null, postcode: string|null}
     */
    public function courierReturnAddress(): array
    {
        return [
            'line' => $this->trimmed($this->return_address_line),
            'area' => $this->trimmed($this->return_area),
            'district' => $this->trimmed($this->return_district),
            'postcode' => $this->trimmed($this->return_postcode),
        ];
    }

    protected function filled(?string $value): bool
    {
        return $this->trimmed($value) !== null;
    }

    protected function trimmed(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'usable_for_courier_labels' => 'boolean',
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
