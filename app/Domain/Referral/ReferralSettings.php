<?php

namespace App\Domain\Referral;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;

/**
 * The global multi-level commission switch (D24).
 *
 * **Off unless someone switched it on.** With it off, no qualifying event is
 * recorded and nothing is calculated, whatever plans exist; commissions already
 * calculated are left to run their course, because they were decided when the
 * switch was on. Switching it is audited with who, when, before, after and why.
 */
class ReferralSettings
{
    public const ENABLED = 'referral.mlm_enabled';

    public function __construct(
        protected SettingsRepository $settings,
        protected RecordAuditLog $audit,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::ENABLED, false);
    }

    public function switchTo(bool $enabled, User $actor, string $reason): void
    {
        $before = $this->enabled();

        $this->settings->define(
            self::ENABLED,
            'referral',
            SettingType::Boolean,
            false,
            label: 'Multi-level Partner Network commission',
            description: 'Whether qualifying events pay Partner Network commission. Off by default (D24).',
        );

        $this->settings->set(self::ENABLED, $enabled, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'referral.mlm_switched',
            actorId: $actor->id,
            auditableType: null,
            auditableId: null,
            before: ['enabled' => $before],
            after: ['enabled' => $enabled],
            reason: $reason,
            module: 'referral',
        ));
    }
}
