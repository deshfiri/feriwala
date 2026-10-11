<?php

namespace App\Domain\ExternalStore\Models;

use App\Casts\MoneyCast;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an external order, as the store reported it (D29).
 *
 * The store's own product, by its own SKU and id — never a Feriwala catalogue
 * product, which this line does not and cannot point at.
 *
 * @property int $id
 * @property int $external_order_id
 * @property int $line_number
 * @property string|null $external_product_id
 * @property string|null $sku
 * @property string $name
 * @property string|null $variant_label
 * @property int $quantity
 * @property string $currency_code
 * @property Money $unit_price
 * @property Money $line_total
 */
class ExternalOrderItem extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'line_number' => 'integer',
            'unit_price' => MoneyCast::class,
            'line_total' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ExternalOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_id');
    }
}
