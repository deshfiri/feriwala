<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Domain\Inventory\ReservationWindows;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Sets how long reservations hold stock (contract §6.1.2, P3-26).
 *
 * The contract makes both windows configurable in ERP settings. The change is not
 * retrospective: every reservation already made carries the expiry it was given,
 * so a customer told they had fifteen minutes still has fifteen minutes.
 */
class SetReservationWindows
{
    public function __construct(
        protected SettingsRepository $settings,
        protected ReservationWindows $windows,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException when a window is outside its bounds
     */
    public function handle(User $actor, int $onlineMinutes, int $codHours): void
    {
        InventoryPolicy::authorize(InventoryPolicy::canApprove($actor));

        if ($onlineMinutes < ReservationWindows::MINIMUM_ONLINE_MINUTES || $onlineMinutes > ReservationWindows::MAXIMUM_ONLINE_MINUTES) {
            throw new InvalidArgumentException('The online payment window is outside its bounds.');
        }

        if ($codHours < ReservationWindows::MINIMUM_COD_HOURS || $codHours > ReservationWindows::MAXIMUM_COD_HOURS) {
            throw new InvalidArgumentException('The cash on delivery window is outside its bounds.');
        }

        $before = [
            'online_minutes' => $this->windows->onlineMinutes(),
            'cod_hours' => $this->windows->codHours(),
        ];

        $this->settings->define(ReservationWindows::ONLINE_MINUTES, 'inventory', SettingType::Integer, label: 'Online payment reservation window (minutes)');
        $this->settings->define(ReservationWindows::COD_HOURS, 'inventory', SettingType::Integer, label: 'Cash on delivery confirmation window (hours)');

        $this->settings->set(ReservationWindows::ONLINE_MINUTES, $onlineMinutes, $actor->id);
        $this->settings->set(ReservationWindows::COD_HOURS, $codHours, $actor->id);

        $this->audit->handle(new AuditEntry(
            action: 'inventory.reservation_windows_set',
            actorId: $actor->id,
            before: $before,
            after: ['online_minutes' => $onlineMinutes, 'cod_hours' => $codHours],
            module: 'inventory',
            // It decides how long stock is held away from every other order.
            isSensitive: true,
        ));
    }
}
