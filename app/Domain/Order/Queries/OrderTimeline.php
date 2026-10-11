<?php

namespace App\Domain\Order\Queries;

use App\Domain\Account\Models\AccountMembership;
use App\Domain\Courier\Enums\ShipmentStatusChangeSource;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Models\ShipmentStatusChange;
use App\Domain\Courier\Models\ShipmentTrackingEvent;
use App\Domain\ExternalStore\Enums\ExternalOrderEventSource;
use App\Domain\ExternalStore\Enums\ExternalOrderEventType;
use App\Domain\ExternalStore\ExternalOrderStatusDisplay;
use App\Domain\ExternalStore\Models\ExternalOrder;
use App\Domain\ExternalStore\Models\ExternalOrderEvent;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Models\User;
use App\Support\StateMachine\TransitionableState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One chronological history of an order, for the partner who owns it (D29).
 *
 * **Read from what was recorded, never reconstructed.** Every entry is a row in
 * a history table that cannot be edited or deleted: the order's own status, its
 * fulfilment, delivery and courier tracks, its shipments and their tracking
 * events, its returns — or, for an external order, the events its store
 * reported. Nothing is inferred from a current column, and a step that was
 * never recorded does not appear.
 *
 * **What a partner may see of it.** Each entry says what changed, when, and
 * which kind of party moved it — the partner, Feriwala staff, the system, the
 * external store, or a courier. A member of the partner's own business is named;
 * a Feriwala staff member is not. Only notes written for the account holder
 * are shown: internal notes and reasons stay with the platform.
 */
class OrderTimeline
{
    public const PARTNER = 'partner';

    public const STAFF = 'staff';

    public const SYSTEM = 'system';

    public const EXTERNAL_STORE = 'external_store';

    public const COURIER = 'courier';

    /**
     * @return list<array<string, mixed>>
     */
    public function forOrder(Order $order): array
    {
        $order->loadMissing([
            'statusHistory',
            'fulfillmentStatusHistory',
            'deliveryStatusHistory',
            'courierStatusHistory',
            'shipments.statusHistory',
            'shipments.trackingEvents',
            'shipments.courierProvider',
        ]);

        /** @var list<int> $members */
        $members = AccountMembership::query()
            ->where('business_account_id', $order->business_account_id)
            ->pluck('user_id')
            ->map(fn (mixed $id) => (int) $id)
            ->values()
            ->all();

        $entries = [];

        foreach (['order_status' => 'statusHistory', 'fulfillment' => 'fulfillmentStatusHistory', 'delivery' => 'deliveryStatusHistory', 'courier' => 'courierStatusHistory'] as $type => $relation) {
            foreach ($order->{$relation} as $change) {
                /** @var Model $change */
                $entries[] = $this->statusEntry($type, $change, $this->orderSource($change->getAttribute('source')), $members);
            }
        }

        /** @var Shipment $shipment */
        foreach ($order->shipments as $shipment) {
            foreach ($shipment->statusHistory as $change) {
                /** @var ShipmentStatusChange $change */
                $source = match ($change->source) {
                    ShipmentStatusChangeSource::Staff => self::STAFF,
                    ShipmentStatusChangeSource::Webhook => self::COURIER,
                    ShipmentStatusChangeSource::System => self::SYSTEM,
                };

                $entries[] = [
                    ...$this->statusEntry('shipment', $change, $source, $members),
                    'reference' => $shipment->reference,
                ];
            }

            foreach ($shipment->trackingEvents as $event) {
                /** @var ShipmentTrackingEvent $event */
                $entries[] = $this->entry(
                    key: 'tracking-'.$event->id,
                    type: 'tracking',
                    at: $event->occurred_at,
                    source: $event->source === 'manual' ? self::STAFF : self::COURIER,
                    new: $event->event_code,
                    newLabel: $event->event_code,
                    description: $event->description,
                    reference: $shipment->reference,
                );
            }
        }

        $returns = OrderReturn::query()->where('order_id', $order->id)->with('statusHistory')->get();

        foreach ($returns as $return) {
            foreach ($return->statusHistory as $change) {
                /** @var Model $change */
                $entries[] = [
                    ...$this->statusEntry('return', $change, $this->orderSource($change->getAttribute('source')), $members),
                    'reference' => $return->reference,
                ];
            }
        }

        return $this->sorted($entries);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forExternalOrder(ExternalOrder $order): array
    {
        $entries = $order->events->map(function (ExternalOrderEvent $event) {
            $isStatus = in_array($event->type, [ExternalOrderEventType::Imported, ExternalOrderEventType::StatusChanged], true);

            return $this->entry(
                key: 'external-'.$event->id,
                type: $event->type->value,
                at: $event->occurred_at ?? $event->recorded_at,
                source: $event->source === ExternalOrderEventSource::ExternalStore ? self::EXTERNAL_STORE : self::SYSTEM,
                previous: $event->previous_value,
                previousLabel: $isStatus ? ExternalOrderStatusDisplay::label($event->previous_value) : $this->paymentLabel($event->previous_value),
                new: $event->new_value,
                newLabel: $isStatus ? ExternalOrderStatusDisplay::label($event->new_value) : $this->paymentLabel($event->new_value),
                description: $event->type === ExternalOrderEventType::PaymentReceived && $event->description !== null
                    ? (string) __('orders.timeline.payment_method', ['method' => $event->description])
                    : null,
                recordedAt: $event->recorded_at,
            );
        })->all();

        return $this->sorted(array_values($entries));
    }

    /**
     * @param  list<int>  $members
     * @return array<string, mixed>
     */
    protected function statusEntry(string $type, Model $change, string $source, array $members): array
    {
        $previous = $change->getAttribute('previous_status');
        $new = $change->getAttribute('new_status');
        $changedBy = $change->getAttribute('changed_by');
        $note = $change->getAttribute('public_note');

        return $this->entry(
            key: $type.'-'.$change->getKey(),
            type: $type,
            at: $change->getAttribute('changed_at'),
            source: $source,
            previous: $previous instanceof \BackedEnum ? (string) $previous->value : null,
            previousLabel: $previous instanceof TransitionableState ? $previous->label() : null,
            new: $new instanceof \BackedEnum ? (string) $new->value : null,
            newLabel: $new instanceof TransitionableState ? $new->label() : null,
            description: is_string($note) ? (string) __($note) : null,
            actor: $source === self::PARTNER && is_int($changedBy) && in_array($changedBy, $members, true)
                ? User::query()->whereKey($changedBy)->value('name')
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function entry(
        string $key,
        string $type,
        mixed $at,
        string $source,
        ?string $previous = null,
        ?string $previousLabel = null,
        ?string $new = null,
        ?string $newLabel = null,
        ?string $description = null,
        mixed $actor = null,
        ?string $reference = null,
        ?CarbonImmutable $recordedAt = null,
    ): array {
        $when = $at instanceof CarbonImmutable ? $at : CarbonImmutable::parse((string) $at);

        return [
            'key' => $key,
            'type' => $type,
            'type_label' => __('orders.timeline.types.'.$type),
            'previous' => $previous,
            'previous_label' => $previousLabel,
            'new' => $new,
            'new_label' => $newLabel,
            'at' => $when->toIso8601String(),
            'recorded_at' => $recordedAt?->toIso8601String(),
            'source' => $source,
            'source_label' => __('orders.timeline.sources.'.$source),
            'actor' => is_string($actor) ? $actor : null,
            'description' => $description,
            'reference' => $reference,
        ];
    }

    protected function orderSource(mixed $source): string
    {
        return match ($source) {
            OrderStatusChangeSource::Account => self::PARTNER,
            OrderStatusChangeSource::Staff => self::STAFF,
            default => self::SYSTEM,
        };
    }

    protected function paymentLabel(?string $state): ?string
    {
        return ExternalOrderStatusDisplay::label($state);
    }

    /**
     * Oldest first; entries at the same moment keep the order they were
     * recorded in.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    protected function sorted(array $entries): array
    {
        $order = array_keys($entries);

        usort($order, fn (int $a, int $b) => [strtotime((string) $entries[$a]['at']), $a] <=> [strtotime((string) $entries[$b]['at']), $b]);

        return array_map(fn (int $index) => $entries[$index], $order);
    }
}
