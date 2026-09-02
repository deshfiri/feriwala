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
    public function currentState(): TransitionableState
    {
        $state = $this->getAttribute($this->stateAttribute());

        if (! $state instanceof TransitionableState) {
            throw new \LogicException(sprintf(
                'The [%s] attribute on %s must cast to a %s.',
                $this->stateAttribute(),
                static::class,
                TransitionableState::class,
            ));
        }

        return $state;
    }

    /**
     * Whether the model may legally move to the given state.
     */
    public function canTransitionTo(TransitionableState $to): bool
    {
        $current = $this->currentState();

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
    public function transitionTo(TransitionableState $to): static
    {
        if (! $this->canTransitionTo($to)) {
            throw IllegalStateTransition::between(static::class, $this->currentState(), $to);
        }

        $this->setAttribute($this->stateAttribute(), $to);

        return $this;
    }

    /**
     * The states reachable from where the model stands now — useful for building
     * an actions menu that only offers legal moves.
     *
     * @return array<int, TransitionableState>
     */
    public function availableTransitions(): array
    {
        return $this->currentState()->transitionsTo();
    }
}
