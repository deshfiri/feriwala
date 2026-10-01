<?php

namespace App\Concerns;

use App\Support\StateMachine\TransitionableState;
use App\Support\StatusHistory\StatusChange;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Records every status change a model makes, in the shared shape (P0-16).
 *
 * For a model that also uses {@see HasStateMachine}: the state machine says
 * whether a move is allowed, and this writes down that it happened — previous
 * status, new status, who, when, why, an internal note and a note the account
 * holder may read (§18.3). The history table keeps those under the same column
 * names everywhere:
 *
 *     previous_status · new_status · changed_by · changed_at
 *     reason · internal_note · public_note
 *
 * plus whatever that particular history adds beside them, passed as
 * `$attributes`. A subject's own columns can never overwrite the shared ones.
 *
 * {@see transitionWithHistory()} makes the move and the record **together, or
 * neither**: a status that changed with no history row, or a history row for a
 * move that did not happen, is the one outcome this exists to rule out. Locking
 * the subject row first is the caller's job, as it is for any transition.
 *
 * The history model uses {@see AppendOnlyStatusHistory}, and its table carries
 * the database guard `feriwala_status_history_is_append_only()`.
 */
trait RecordsStatusHistory
{
    /**
     * The rows recording this model's status changes.
     *
     * @return HasMany<covariant Model, $this>
     */
    abstract public function statusHistory(): HasMany;

    /**
     * Move to a new state and record the move, in one transaction.
     *
     * A model with more than one independent lifecycle passes `$attribute`
     * (the status column) and `$historyRelation` (the method on this model
     * returning that axis's own history relation) together — both default to
     * the model's single default axis, so every existing call site that
     * passes neither keeps moving `status` into {@see statusHistory()}
     * exactly as before.
     *
     * @param  array<string, mixed>  $attributes  columns this history keeps beyond the shared contract
     */
    public function transitionWithHistory(
        TransitionableState $to,
        StatusChange $change,
        array $attributes = [],
        ?string $attribute = null,
        ?string $historyRelation = null,
    ): Model {
        return $this->getConnection()->transaction(function () use ($to, $change, $attributes, $attribute, $historyRelation) {
            $previous = $this->currentState($attribute);

            $this->transitionTo($to, $attribute)->save();

            return $this->recordStatusChange($previous, $to, $change, $attributes, $historyRelation);
        });
    }

    /**
     * Record a status change without moving anything — for the first status a
     * model is created in, which has no previous state to transition from.
     *
     * @param  array<string, mixed>  $attributes  columns this history keeps beyond the shared contract
     */
    public function recordStatusChange(
        ?TransitionableState $previous,
        TransitionableState $new,
        StatusChange $change,
        array $attributes = [],
        ?string $historyRelation = null,
    ): Model {
        /** @var HasMany<Model, $this> $relation */
        $relation = $historyRelation === null ? $this->statusHistory() : $this->{$historyRelation}();

        return $relation->create([
            ...$attributes,
            'previous_status' => $previous === null ? null : $this->statusHistoryValue($previous),
            'new_status' => $this->statusHistoryValue($new),
            'changed_by' => $change->actorId,
            'changed_at' => $change->at ?? CarbonImmutable::now(),
            'reason' => $change->reason,
            'internal_note' => $change->internalNote,
            'public_note' => $change->publicNote,
        ]);
    }

    protected function statusHistoryValue(TransitionableState $state): string|int
    {
        if (! $state instanceof BackedEnum) {
            throw new LogicException(sprintf(
                'Status history stores a backed enum value; %s is not one.',
                $state::class,
            ));
        }

        return $state->value;
    }
}
