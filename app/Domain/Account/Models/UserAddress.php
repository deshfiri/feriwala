<?php

namespace App\Domain\Account\Models;

use App\Domain\Account\Enums\AddressType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address held against an account (§5.2).
 *
 * @property AddressType $type
 */
class UserAddress extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => AddressType::class,
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A flat snapshot for storing on an order.
     *
     * Orders keep their own copy so that editing an address later never rewrites
     * where a past order was actually delivered (contract §6.2).
     *
     * @return array<string, string|null>
     */
    public function toSnapshot(): array
    {
        return [
            'contact_name' => $this->contact_name,
            'contact_mobile' => $this->contact_mobile,
            'line_1' => $this->line_1,
            'line_2' => $this->line_2,
            'area' => $this->area,
            'city' => $this->city,
            'district' => $this->district,
            'postcode' => $this->postcode,
            'country' => $this->country,
        ];
    }
}
