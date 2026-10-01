<?php

namespace App\Concerns;

use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use App\Support\StateMachine\TransitionableState;

/**
 * Guards a model's status column so only declared transitions are possible.
 *
 * The model names its status column via {@see stateAttribute()}; the enum in that
 * column declares where it may go next. Nothing else in the application should
 * assign the status attribute directly — go through transitionTo() so the move is
 * checked, and so status history has a single place to hook into (§18.3).
 *
 * A model that carries more than one independent lifecycle on separate columns
 * (an order's own status beside its fulfilment/delivery/courier status, say)
 * passes `$attribute` explicitly to every method here rather than overriding
 * {@see stateAttribute()} a second time — that method names only the default
 * axis, used when nothing more specific is given.
 */
trait HasStateMachine
{
    /**
     * The attribute holding the state. Override when it is not `status`.
     */
    public function stateAttribute(): string
    {
        return 'status';
    }

    /**
     * The model's current state.
     */
    public function currentState(?string $attribute = null): TransitionableState
    {
        $attribute ??= $this->stateAttribute();
        $state = $this->getAttribute($attribute);

        if (! $state instanceof TransitionableState) {
            throw new \LogicException(sprintf(
                'The [%s] attribute on %s must cast to a %s.',
                $attribute,
                static::class,
                TransitionableState::class,
            ));
        }

        return $state;
    }

    /**
     * Whether the model may legally move to the given state.
     */
    public function canTransitionTo(TransitionableState $to, ?string $attribute = null): bool
    {
        $current = $this->currentState($attribute);

        if ($current === $to) {
            return false;
        }

        foreach ($current->transitionsTo() as $allowed) {
            if ($allowed === $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move to the given state, or throw if the move is not allowed.
     *
     * Does not persist — the caller decides when to save, so the transition can
     * take part in the surrounding database transaction alongside its ledger
     * entries and history row.
     */
    public function transitionTo(TransitionableState $to, ?string $attribute = null): static
    {
        $attribute ??= $this->stateAttribute();

        if (! $this->canTransitionTo($to, $attribute)) {
            throw IllegalStateTransition::between(static::class, $this->currentState($attribute), $to);
        }

        $this->setAttribute($attribute, $to);

        return $this;
    }

    /**
     * The states reachable from where the model stands now — useful for building
     * an actions menu that only offers legal moves.
     *
     * @return array<int, TransitionableState>
     */
    public function availableTransitions(?string $attribute = null): array
    {
        return $this->currentState($attribute)->transitionsTo();
    }
}
