<?php

namespace App\Domain\ExternalStore\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\ExternalStore\Enums\ExternalStoreStatus;
use App\Domain\ExternalStore\Enums\StorePlatform;
use App\Integrations\Store\Data\StoreEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A partner's own store on another platform, connected to their account (D29).
 *
 * The credentials are encrypted at rest, hidden from every serialisation, and
 * read only to call the store from the server. Nothing here ever puts them in
 * a page, a log or an error.
 *
 * @property int $id
 * @property string $public_id
 * @property int $business_account_id
 * @property StorePlatform $platform
 * @property string $name
 * @property string $base_url
 * @property string $normalized_host
 * @property string|null $api_key
 * @property string|null $api_secret
 * @property string|null $api_key_hint
 * @property string|null $webhook_secret
 * @property ExternalStoreStatus $status
 * @property int $order_cursor
 * @property CarbonImmutable|null $orders_updated_through
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $last_failure_at
 * @property string|null $last_error
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $last_webhook_at
 * @property CarbonImmutable|null $disconnected_at
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read int|null $orders_count
 * @property-read BusinessAccount $businessAccount
 * @property-read Collection<int, ExternalStoreStatusChange> $statusHistory
 * @property-read Collection<int, ExternalOrder> $orders
 */
class ExternalStore extends Model
{
    use HasPublicId, HasStateMachine, RecordsStatusHistory;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['api_key', 'api_secret', 'webhook_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => StorePlatform::class,
            'status' => ExternalStoreStatus::class,
            'api_key' => 'encrypted',
            'api_secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'order_cursor' => 'integer',
            'consecutive_failures' => 'integer',
            'orders_updated_through' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'last_webhook_at' => 'immutable_datetime',
            'disconnected_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return HasMany<ExternalStoreStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(ExternalStoreStatusChange::class)->orderBy('id');
    }

    /**
     * @return HasMany<ExternalOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(ExternalOrder::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAccount(Builder $query, BusinessAccount $account): Builder
    {
        return $query->where('business_account_id', $account->id);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', '!=', ExternalStoreStatus::Disconnected->value);
    }

    /**
     * Where and how to call the store. Refuses a disconnected store, whose
     * credentials are gone on purpose.
     */
    public function endpoint(): StoreEndpoint
    {
        if ($this->api_key === null || $this->api_secret === null) {
            throw new LogicException('A disconnected store has no credentials to call it with.');
        }

        return new StoreEndpoint($this->base_url, $this->api_key, $this->api_secret);
    }
}
