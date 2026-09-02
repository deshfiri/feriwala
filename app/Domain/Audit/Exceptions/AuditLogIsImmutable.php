<?php

namespace App\Domain\Audit\Exceptions;

use RuntimeException;

/**
 * Raised when something tries to change or remove an audit entry.
 *
 * Never caught and swallowed — reaching this exception means code is attempting
 * something the specification forbids (§36.2), and that should stop the request
 * rather than be worked around.
 */
class AuditLogIsImmutable extends RuntimeException
{
    public static function cannotUpdate(?string $publicId = null): self
    {
        return new self(sprintf(
            'Audit log %s cannot be updated. The audit trail is append-only (requirements.txt §36.2); '
            .'record a new entry describing the correction instead.',
            $publicId ?? '(unsaved)',
        ));
    }

    public static function cannotDelete(?string $publicId = null): self
    {
        return new self(sprintf(
            'Audit log %s cannot be deleted. The audit trail is append-only (requirements.txt §36.2); '
            .'retention is handled by policy, never by removing individual entries.',
            $publicId ?? '(unsaved)',
        ));
    }
}
