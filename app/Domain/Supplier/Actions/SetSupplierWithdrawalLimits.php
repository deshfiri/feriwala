<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\SupplierWithdrawalLimits;
use App\Models\User;

/**
 * Set the global default Supplier withdrawal limits, or one Supplier's
 * override (D25, P13-24). Authorisation is the controller's job, as it is
 * for every other Supplier action.
 */
class SetSupplierWithdrawalLimits
{
    public function __construct(
        protected SettingsRepository $settings,
        protected RecordAuditLog $audit,
    ) {}

    public function setDefault(User $actor, ?int $minimumMinor, ?int $maximumMinor): void
    {
        $this->settings->define(SupplierWithdrawalLimits::MINIMUM_MINOR, 'supplier', SettingType::Integer, label: 'Supplier withdrawal minimum (minor units)');
        $this->settings->define(SupplierWithdrawalLimits::MAXIMUM_MINOR, 'supplier', SettingType::Integer, label: 'Supplier withdrawal maximum (minor units)');

        $this->settings->set(SupplierWithdrawalLimits::MINIMUM_MINOR, $minimumMinor, $actor->id);
        $this->settings->set(SupplierWithdrawalLimits::MAXIMUM_MINOR, $maximumMinor, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'supplier.withdrawal_limits_default_set',
            actorId: $actor->id,
            after: ['minimum_minor' => $minimumMinor, 'maximum_minor' => $maximumMinor],
            module: 'supplier',
            isSensitive: true,
        ));
    }

    public function setOverride(User $actor, Supplier $supplier, ?int $minimumMinor, ?int $maximumMinor): Supplier
    {
        $before = [
            'minimum_minor' => $supplier->withdrawal_minimum_override_minor,
            'maximum_minor' => $supplier->withdrawal_maximum_override_minor,
        ];

        $supplier->forceFill([
            'withdrawal_minimum_override_minor' => $minimumMinor,
            'withdrawal_maximum_override_minor' => $maximumMinor,
        ])->save();

        $this->audit->handle(new AuditEntry(
            action: 'supplier.withdrawal_limit_override_set',
            actorId: $actor->id,
            auditableType: Supplier::class,
            auditableId: $supplier->id,
            before: $before,
            after: ['minimum_minor' => $minimumMinor, 'maximum_minor' => $maximumMinor],
            accountId: $supplier->id,
            module: 'supplier',
            isSensitive: true,
        ));

        return $supplier;
    }
}
