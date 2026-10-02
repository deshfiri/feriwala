<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Data\DeliveryChargeCalculation;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Enums\ShipmentStatusChangeSource;
use App\Domain\Courier\Exceptions\CourierProviderUnavailable;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Actions\AdvanceOrderCourierStatus;
use App\Domain\Order\Actions\AdvanceOrderDeliveryStatus;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Models\Order;
use App\Integrations\Courier\CourierManager;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Raise a shipment for an order's ready-to-dispatch lines (Advanced Order
 * Management batch, Commit 5; §18, §21).
 *
 * The decision this freezes -- which provider, what the delivery costs --
 * never changes afterwards (the migration's locked-columns trigger).
 * `delivery_charge` may be a staff-entered figure (D8's manual-first path,
 * `$calculation` left null, `delivery_charge_rule_snapshot` stays null since
 * there is no rule to snapshot when a person typed the number) or {@see
 * \App\Domain\Billing\CalculateDeliveryCharge}'s own computed result (beta-
 * critical batch, Commit 2) -- when a calculation is given, its exact
 * breakdown is frozen into the snapshot column so a later rule or settings
 * change can never reach back and alter what this shipment already charged.
 *
 * Moves the order's {@see OrderCourierStatus} to `Assigned` and its {@see
 * OrderDeliveryStatus} to `CourierAssigned` through the existing Commit 1
 * actions, never by assigning either attribute directly -- those actions are
 * the only place that enforces the move is actually legal from the order's
 * current state, so this action does not duplicate that check.
 */
class CreateShipmentFromAllocations
{
    public function __construct(
        protected DatabaseManager $database,
        protected CourierManager $courier,
        protected AdvanceOrderCourierStatus $advanceCourier,
        protected AdvanceOrderDeliveryStatus $advanceDelivery,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @param  array<int, array{weight?: string|null, length?: string|null, width?: string|null, height?: string|null, package_size?: string|null}>  $packages
     */
    public function handle(
        Order $order,
        CourierProviderCode $providerCode,
        Money $deliveryCharge,
        array $packages,
        ?User $actor,
        ?string $trackingNumber = null,
        ?Money $codAmount = null,
        ?DeliveryChargeCalculation $calculation = null,
    ): Shipment {
        if (! $this->courier->isAvailable($providerCode)) {
            throw CourierProviderUnavailable::forProvider($providerCode, $this->courier->isImplemented($providerCode));
        }

        if ($packages === []) {
            throw new InvalidArgumentException('At least one package is required to create a shipment.');
        }

        return $this->database->transaction(function () use ($order, $providerCode, $deliveryCharge, $packages, $actor, $trackingNumber, $codAmount, $calculation) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            /** @var CourierProvider $provider */
            $provider = CourierProvider::query()->where('code', $providerCode->value)->firstOrFail();

            $shipment = Shipment::create([
                'order_id' => $locked->id,
                'courier_provider_id' => $provider->id,
                'status' => OrderCourierStatus::Assigned,
                'tracking_number' => $trackingNumber,
                'delivery_charge' => $deliveryCharge,
                'delivery_charge_rule_snapshot' => $calculation?->toArray(),
                'cod_amount' => $codAmount,
            ]);

            $shipment->recordStatusChange(
                null,
                OrderCourierStatus::Assigned,
                new StatusChange(actorId: $actor?->id, reason: 'Shipment created.'),
                ['source' => ShipmentStatusChangeSource::Staff],
            );

            foreach ($packages as $package) {
                $shipment->packages()->create($package);
            }

            $this->courier->driver($providerCode)->createShipment($shipment);

            $this->advanceCourier->handle($locked, OrderCourierStatus::Assigned, $actor);
            $this->advanceDelivery->handle($locked->refresh(), OrderDeliveryStatus::CourierAssigned, $actor);

            $this->audit->handle(new AuditEntry(
                action: 'shipment.created',
                actorId: $actor?->id,
                auditableType: Shipment::class,
                auditableId: $shipment->id,
                after: [
                    'order' => $locked->reference,
                    'provider' => $providerCode->value,
                    'tracking_number' => $trackingNumber,
                ],
                module: PermissionModule::Courier->value,
            ));

            return $shipment->refresh();
        });
    }
}
