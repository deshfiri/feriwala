<?php

namespace App\Domain\ExternalStore\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\ExternalStore\Enums\ExternalStoreStatus;
use App\Domain\ExternalStore\Enums\ExternalStoreStatusChangeSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to a store connection's status (D29). Append-only.
 *
 * @property int $id
 * @property int $external_store_id
 * @property ExternalStoreStatus|null $previous_status
 * @property ExternalStoreStatus $new_status
 * @property ExternalStoreStatusChangeSource $source
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 */
class ExternalStoreStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'external_store_status_history';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => ExternalStoreStatus::class,
            'new_status' => ExternalStoreStatus::class,
            'source' => ExternalStoreStatusChangeSource::class,
        ];
    }

    /**
     * @return BelongsTo<ExternalStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(ExternalStore::class, 'external_store_id');
    }
}
