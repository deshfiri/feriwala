<?php

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Audit\Models\AuditLog;

/**
 * Writes one entry to the audit trail.
 *
 * The single way audit rows are created. Centralising it means redaction happens
 * once, in one place, rather than depending on every caller remembering that
 * §42 forbids passwords, tokens, and gateway secrets from reaching a log.
 */
class RecordAuditLog
{
    /**
     * Attribute names whose values are never written, at any nesting depth.
     *
     * Matched case-insensitively against a substring of the key, so `password`
     * also catches `password_confirmation` and `current_password`.
     */
    public const REDACTED = [
        'password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'private_key',
        'credential',
        'authorization',
        'signature',
        'cvv',
        'card_number',
        'pin',
        'otp',
        'remember_token',
    ];

    public const REDACTION = '[redacted]';

    public function handle(AuditEntry $entry): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $entry->actorId,
            'actor_type' => $entry->actorType,
            'actor_label' => $entry->actorLabel,
            'action' => $entry->action,
            'auditable_type' => $entry->auditableType,
            'auditable_id' => $entry->auditableId,
            'before' => $this->redact($entry->before),
            'after' => $this->redact($entry->after),
            'reason' => $entry->reason,
            'note' => $entry->note,
            'ip_address' => $entry->ipAddress,
            'user_agent' => $entry->userAgent,
            'account_id' => $entry->accountId,
            'module' => $entry->module,
            'is_sensitive' => $entry->isSensitive,
        ]);
    }

    /**
     * Replace sensitive values while keeping the shape of the change visible.
     *
     * The key is kept so a reader can see that a password was changed; only the
     * value is removed. Knowing "the password field changed" is the audit
     * information — the value never is.
     *
     * @param  array<array-key, mixed>|null  $values
     * @return array<array-key, mixed>|null
     */
    public function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->redact($value);

                continue;
            }

            if (is_string($key) && $this->isSensitiveKey($key)) {
                $values[$key] = self::REDACTION;
            }
        }

        return $values;
    }

    protected function isSensitiveKey(string $key): bool
    {
        $normalised = str_replace('-', '_', mb_strtolower($key));

        foreach (self::REDACTED as $needle) {
            if (str_contains($normalised, $needle)) {
                return true;
            }
        }

        return false;
    }
}
