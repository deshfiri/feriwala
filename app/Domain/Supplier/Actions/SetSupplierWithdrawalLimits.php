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
 *
 * `$minimum`/`$maximum` are exact decimal Taka strings (e.g. `"500.00"`),
 * already parsed at the HTTP boundary — never a bare number (D26).
 */
class SetSupplierWithdrawalLimits
{
    public function __construct(
        protected SettingsRepository $settings,
        protected RecordAuditLog $audit,
    ) {}

    public function setDefault(User $actor, ?string $minimum, ?string $maximum): void
    {
        $this->settings->define(SupplierWithdrawalLimits::MINIMUM_SETTING, 'supplier', SettingType::Decimal, label: 'Supplier withdrawal minimum');
        $this->settings->define(SupplierWithdrawalLimits::MAXIMUM_SETTING, 'supplier', SettingType::Decimal, label: 'Supplier withdrawal maximum');

        $this->settings->set(SupplierWithdrawalLimits::MINIMUM_SETTING, $minimum, $actor->id);
        $this->settings->set(SupplierWithdrawalLimits::MAXIMUM_SETTING, $maximum, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'supplier.withdrawal_limits_default_set',
            actorId: $actor->id,
            after: ['minimum' => $minimum, 'maximum' => $maximum],
            module: 'supplier',
            isSensitive: true,
        ));
    }

    public function setOverride(User $actor, Supplier $supplier, ?string $minimum, ?string $maximum): Supplier
    {
        $before = [
            'minimum' => $supplier->withdrawal_minimum_override,
            'maximum' => $supplier->withdrawal_maximum_override,
        ];

        $supplier->forceFill([
            'withdrawal_minimum_override' => $minimum,
            'withdrawal_maximum_override' => $maximum,
        ])->save();

        $this->audit->handle(new AuditEntry(
            action: 'supplier.withdrawal_limit_override_set',
            actorId: $actor->id,
            auditableType: Supplier::class,
            auditableId: $supplier->id,
            before: $before,
            after: ['minimum' => $minimum, 'maximum' => $maximum],
            accountId: $supplier->id,
            module: 'supplier',
            isSensitive: true,
        ));

        return $supplier;
    }
}
