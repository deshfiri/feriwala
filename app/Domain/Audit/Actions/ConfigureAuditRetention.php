<?php

namespace App\Domain\Audit\Actions;

use App\Domain\Audit\AuditLogAuthority;
use App\Domain\Audit\AuditRetention;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Sets how long the audit log is kept, or turns automatic deletion off.
 *
 * Audited, and sensitive: shortening it is how a trail is quietly cut down,
 * so it is the Super Admin's alone ({@see AuditLogAuthority}).
 */
class ConfigureAuditRetention
{
    public function __construct(
        protected SettingsRepository $settings,
        protected AuditRetention $retention,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  int|null  $days  null or 0 keeps entries without limit
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException when the figure is negative or beyond the maximum
     */
    public function handle(User $actor, ?int $days): void
    {
        if (! AuditLogAuthority::mayManageRetention($actor)) {
            throw new AuthorizationException('You may not change how long the activity log is kept.');
        }

        $days ??= 0;

        if ($days < 0 || $days > AuditRetention::MAXIMUM_DAYS) {
            throw new InvalidArgumentException('Retention is between 0 and '.AuditRetention::MAXIMUM_DAYS.' days.');
        }

        $before = $this->retention->days();

        $this->settings->define(
            AuditRetention::SETTING,
            'audit',
            SettingType::Integer,
            0,
            label: 'Days the activity log is kept (0 keeps it forever)',
        );

        $this->settings->set(AuditRetention::SETTING, $days, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'audit.retention_changed',
            actorId: $actor->id,
            before: ['days' => $before],
            after: ['days' => $days === 0 ? null : $days],
            module: 'audit',
            isSensitive: true,
        ));
    }
}
