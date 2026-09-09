<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\PaymentDeadline;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use InvalidArgumentException;

/**
 * Sets how long an unpaid checkout stays open (§9).
 *
 * The window is not retrospective. Changing it decides how long the *next*
 * checkout has; every payment already recorded carries the deadline it was
 * given, so an applicant told they had until Friday still has until Friday.
 *
 * Zero, or clearing the field, turns deadlines off — the state a fresh
 * installation is in, and one an administrator may legitimately want back.
 */
class SetPaymentDeadline
{
    public function __construct(
        protected SettingsRepository $settings,
        protected RecordAuditLog $audit,
    ) {}

    public function handle(User $actor, ?int $hours): void
    {
        if ($hours !== null && $hours < 0) {
            throw new InvalidArgumentException('A deadline cannot be negative.');
        }

        if ($hours !== null && $hours > PaymentDeadline::MAXIMUM_HOURS) {
            throw new InvalidArgumentException(
                'A deadline longer than '.PaymentDeadline::MAXIMUM_HOURS.' hours is not a deadline.'
            );
        }

        $before = $this->settings->get(PaymentDeadline::HOURS);

        // Defined on write rather than by a migration: it is opt-in, and a row
        // that exists at zero says the same thing as no row at all.
        $this->settings->define(
            PaymentDeadline::HOURS,
            'billing',
            SettingType::Integer,
            label: 'Payment deadline (hours)',
        );

        $this->settings->set(PaymentDeadline::HOURS, $hours ?? 0, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'billing.payment_deadline_set',
            actorId: $actor->id,
            before: ['hours' => $before],
            after: ['hours' => $hours],
            module: 'payment',
            // It decides when somebody's checkout — and their coupon — dies.
            isSensitive: true,
        ));
    }
}
