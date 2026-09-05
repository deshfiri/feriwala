<?php

namespace App\Domain\Account\Exceptions;

use RuntimeException;

/**
 * The package's staff limit has no room left (§8.1).
 *
 * Carries the numbers because the message a manager needs is "you have 3 of 3
 * used, two of them invitations nobody has accepted yet" — not "limit reached",
 * which leaves them wondering whether to buy a bigger package or chase someone.
 */
class StaffLimitReached extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $limit,
        public readonly int $used,
        public readonly int $openInvitations,
    ) {
        parent::__construct($message);
    }

    public static function at(int $limit, int $used, int $openInvitations): self
    {
        $message = $openInvitations > 0
            ? sprintf(
                'This package allows %d staff and %d are already taken, including %d invitation(s) not yet accepted.',
                $limit,
                $used,
                $openInvitations,
            )
            : sprintf('This package allows %d staff and %d are already taken.', $limit, $used);

        return new self($message, $limit, $used, $openInvitations);
    }
}
