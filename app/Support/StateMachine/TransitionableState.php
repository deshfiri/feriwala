<?php

namespace App\Support\StateMachine;

/**
 * Implemented by status enums that govern their own transitions.
 *
 * Feriwala carries very large status sets — 22 account statuses, 28 order
 * statuses plus admin-defined ones, 14 website statuses, and more. Declaring the
 * legal moves next to the states themselves is what stops a Refunded order being
 * dragged back to Processing, or a Closed account quietly becoming Active
 * (requirements.txt §5.3, §11.2, §16.4, §18.2, §22.3, §23.3, §27.5).
 */
interface TransitionableState
{
    /**
     * The states this state may legally move to.
     *
     * @return array<int, static>
     */
    public function transitionsTo(): array;

    /**
     * Human-readable label for display.
     */
    public function label(): string;

    /**
     * Whether this is an end state that nothing may follow.
     */
    public function isTerminal(): bool;
}
