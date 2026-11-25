<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use InvalidArgumentException;

/**
 * A member of staff records what they found when they checked a partner's
 * preferred domain by hand (D39) — the only way availability is ever recorded,
 * since Banij has no registrar connection. Nothing is bought here (D9); the
 * domain is checked again before it is.
 */
class RecordPreferredDomainCheck
{
    public const AVAILABLE = 'available';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        protected RecordAuditLog $audit,
    ) {}

    public function handle(BusinessAccount $account, User $actor, string $result, ?string $note = null): BusinessAccount
    {
        if (! in_array($result, [self::AVAILABLE, self::UNAVAILABLE], true)) {
            throw new InvalidArgumentException('A domain check is either available or unavailable.');
        }

        if ($account->preferredDomain() === null) {
            throw new InvalidArgumentException('This partner has not chosen a preferred domain yet.');
        }

        $account->forceFill([
            'preferred_domain_check_result' => $result,
            'preferred_domain_checked_at' => now(),
            'preferred_domain_checked_by' => $actor->id,
            'preferred_domain_check_note' => $note === null || trim($note) === '' ? null : trim($note),
        ])->save();

        $this->audit->handle(new AuditEntry(
            action: 'onboarding.preferred_domain_checked',
            actorId: $actor->id,
            auditableType: BusinessAccount::class,
            auditableId: $account->id,
            after: ['domain' => $account->preferredDomain(), 'result' => $result],
            note: $note,
            accountId: $account->id,
            module: 'account',
        ));

        return $account;
    }
}
