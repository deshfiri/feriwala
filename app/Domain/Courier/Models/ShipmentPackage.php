<?php

namespace App\Domain\Courier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical package making up a shipment (Advanced Order Management
 * batch, Commit 5).
 *
 * @property int $id
 * @property int $shipment_id
 * @property string|null $weight
 * @property string|null $length
 * @property string|null $width
 * @property string|null $height
 * @property string|null $package_size
 */
class ShipmentPackage extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'decimal:3',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
