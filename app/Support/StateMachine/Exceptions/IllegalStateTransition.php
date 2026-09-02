<?php

namespace App\Support\StateMachine\Exceptions;

use App\Support\StateMachine\TransitionableState;
use DomainException;

class IllegalStateTransition extends DomainException
{
    public static function between(
        string $model,
        TransitionableState $from,
        TransitionableState $to,
    ): self {
        $allowed = array_map(
            fn (TransitionableState $state) => $state->label(),
            $from->transitionsTo(),
        );

        return new self(sprintf(
            '%s cannot move from [%s] to [%s]. Allowed from here: %s.',
            class_basename($model),
            $from->label(),
            $to->label(),
            $allowed === [] ? 'nothing — this is an end state' : implode(', ', $allowed),
        ));
    }
}
