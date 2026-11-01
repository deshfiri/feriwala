<?php

namespace App\Domain\Courier\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * That a courier's push was received, once.
 *
 * Holds a hash of the body and the routing facts -- never the body, which
 * carries a customer's details. Unique per (provider, event key), which is what
 * makes a repeated delivery harmless.
 *
 * @property int $id
 * @property int $courier_provider_id
 * @property int|null $shipment_id
 * @property string $event_key
 * @property string $payload_hash
 * @property string|null $outcome
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 */
class CourierWebhookReceipt extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
