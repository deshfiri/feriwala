<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A status history row: written once, never edited or removed (P0-16).
 *
 * A history that can be edited cannot settle an argument about what happened,
 * so an update or a delete through Eloquent is refused here, and the table
 * refuses one from anywhere else through the shared database guard
 * `feriwala_status_history_is_append_only()`. A correction is a new change.
 *
 * The row carries its own time in `changed_at` rather than Eloquent's
 * timestamps: an `updated_at` on a row that can never be updated is a column
 * that could only ever disagree with `created_at`.
 */
trait AppendOnlyStatusHistory
{
    public static function bootAppendOnlyStatusHistory(): void
    {
        static::updating(fn () => throw new LogicException(
            'Status history is append-only. Record a new change instead.'
        ));

        static::deleting(fn () => throw new LogicException(
            'Status history is append-only and cannot be deleted.'
        ));
    }

    public function initializeAppendOnlyStatusHistory(): void
    {
        $this->timestamps = false;

        $this->mergeCasts(['changed_at' => 'immutable_datetime']);
    }

    /**
     * The person who made the change, when a person did.
     *
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Whether the system made this change rather than a person.
     */
    public function wasAutomatic(): bool
    {
        return $this->getAttribute('changed_by') === null;
    }
}
