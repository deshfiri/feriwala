<?php

namespace App\Domain\ExternalStore\Models;

use App\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somebody who bought from one of a partner's external stores (D29).
 *
 * Known **within one store only**: two stores' customers who share a name or a
 * phone number are two records, because nothing proves they are one person.
 *
 * @property int $id
 * @property string $public_id
 * @property int $business_account_id
 * @property int $external_store_id
 * @property string $identity_key
 * @property string|null $external_id
 * @property string|null $name
 * @property string|null $phone
 * @property string|null $email
 * @property CarbonImmutable|null $first_order_at
 * @property CarbonImmutable|null $last_order_at
 * @property-read ExternalStore $store
 */
class ExternalCustomer extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_order_at' => 'immutable_datetime',
            'last_order_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ExternalStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(ExternalStore::class, 'external_store_id');
    }

    /**
     * @return HasMany<ExternalOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(ExternalOrder::class);
    }
}
