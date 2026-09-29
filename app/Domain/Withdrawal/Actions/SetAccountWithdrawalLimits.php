<?php

namespace App\Domain\Withdrawal\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Supplier\Actions\SetSupplierWithdrawalLimits;
use App\Domain\Withdrawal\AccountWithdrawalLimits;
use App\Models\User;

/**
 * Set the global default Client/Partner withdrawal limits, or one
 * BusinessAccount's override (§27) -- mirrors
 * {@see SetSupplierWithdrawalLimits} exactly for
 * a different owner. Authorisation is the controller's job, as it is for
 * every other admin settings action.
 *
 * `$minimum`/`$maximum` are exact decimal Taka strings (e.g. `"500.00"`),
 * already parsed at the HTTP boundary -- never a bare number (D26).
 */
class SetAccountWithdrawalLimits
{
    public function __construct(
        protected SettingsRepository $settings,
        protected RecordAuditLog $audit,
    ) {}

    public function setDefault(User $actor, ?string $minimum, ?string $maximum): void
    {
        $this->settings->define(AccountWithdrawalLimits::MINIMUM_SETTING, 'account', SettingType::Decimal, label: 'Client/Partner withdrawal minimum');
        $this->settings->define(AccountWithdrawalLimits::MAXIMUM_SETTING, 'account', SettingType::Decimal, label: 'Client/Partner withdrawal maximum');

        $this->settings->set(AccountWithdrawalLimits::MINIMUM_SETTING, $minimum, $actor->id);
        $this->settings->set(AccountWithdrawalLimits::MAXIMUM_SETTING, $maximum, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'account.withdrawal_limits_default_set',
            actorId: $actor->id,
            after: ['minimum' => $minimum, 'maximum' => $maximum],
            module: 'withdrawal',
            isSensitive: true,
        ));
    }

    public function setOverride(User $actor, BusinessAccount $account, ?string $minimum, ?string $maximum): BusinessAccount
    {
        $before = [
            'minimum' => $account->withdrawal_minimum_override,
            'maximum' => $account->withdrawal_maximum_override,
        ];

        $account->forceFill([
            'withdrawal_minimum_override' => $minimum,
            'withdrawal_maximum_override' => $maximum,
        ])->save();

        $this->audit->handle(new AuditEntry(
            action: 'account.withdrawal_limit_override_set',
            actorId: $actor->id,
            auditableType: BusinessAccount::class,
            auditableId: $account->id,
            before: $before,
            after: ['minimum' => $minimum, 'maximum' => $maximum],
            accountId: $account->id,
            module: 'withdrawal',
            isSensitive: true,
        ));

        return $account;
    }
}
