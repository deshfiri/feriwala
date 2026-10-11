<?php

namespace App\Domain\ExternalStore\Models;

use App\Domain\ExternalStore\Enums\ExternalOrderEventSource;
use App\Domain\ExternalStore\Enums\ExternalOrderEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One thing the store said happened to an external order (D29). Append-only.
 *
 * @property int $id
 * @property int $external_order_id
 * @property ExternalOrderEventType $type
 * @property ExternalOrderEventSource $source
 * @property string|null $previous_value
 * @property string|null $new_value
 * @property string|null $description
 * @property string|null $external_event_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable $recorded_at
 */
class ExternalOrderEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('An external order\'s history is append-only.'));
        static::deleting(fn () => throw new LogicException('An external order\'s history cannot be deleted.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ExternalOrderEventType::class,
            'source' => ExternalOrderEventSource::class,
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
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
