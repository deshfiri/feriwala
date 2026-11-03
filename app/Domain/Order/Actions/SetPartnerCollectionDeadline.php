<?php

namespace App\Domain\Order\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Policies\BillingSettingsPolicy;
use App\Domain\Order\PartnerCollectionDeadline;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Sets how long a Partner-gateway order may wait for the Partner's Wallet (D36).
 *
 * Not retrospective: every collection already open carries the deadline it was
 * given, so a Partner told they had a day still has a day.
 */
class SetPartnerCollectionDeadline
{
    public function __construct(
        protected SettingsRepository $settings,
        protected PartnerCollectionDeadline $deadline,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException when the figure is outside its bounds
     */
    public function handle(User $actor, int $hours): void
    {
        if (! BillingSettingsPolicy::canManageGateways($actor)) {
            throw new AuthorizationException('You may not change how long Partner-gateway orders wait for the Wallet.');
        }

        if ($hours < PartnerCollectionDeadline::MINIMUM_HOURS || $hours > PartnerCollectionDeadline::MAXIMUM_HOURS) {
            throw new InvalidArgumentException('The Wallet collection deadline is outside its bounds.');
        }

        $before = $this->deadline->hours();

        $this->settings->define(PartnerCollectionDeadline::SETTING, 'orders', SettingType::Integer, label: 'Partner Wallet collection deadline (hours)');
        $this->settings->set(PartnerCollectionDeadline::SETTING, $hours, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'orders.partner_collection_deadline_set',
            actorId: $actor->id,
            before: ['hours' => $before],
            after: ['hours' => $hours],
            module: 'orders',
            // It decides how long stock is held back for an order Banij has not been paid for.
            isSensitive: true,
        ));
    }
}
