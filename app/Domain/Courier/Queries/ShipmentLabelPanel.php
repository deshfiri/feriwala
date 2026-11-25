<?php

namespace App\Domain\Courier\Queries;

use App\Domain\Courier\Enums\ShipmentLabelStatus;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * What a screen shows about a parcel's shipping label.
 *
 * **Two payloads, deliberately different.** Staff see the label's state, why
 * one could not be issued, every version with its reason, and what they may do
 * about it. A Partner sees, for their own order only, which parcels have a
 * label ready to print and the address of their own print page -- never a
 * blocker's detail, a warehouse, a courier setting or anything of the
 * platform's.
 */
class ShipmentLabelPanel
{
    /**
     * @return array<string, mixed>
     */
    public function forStaff(Shipment $shipment, User $actor, bool $withVersions = false): array
    {
        $shipment->loadMissing(['order', 'currentLabel']);

        $current = $shipment->currentLabel;
        $status = ShipmentLabelStatus::of($shipment, $current);
        $manage = Gate::forUser($actor)->allows('manageShipments', Shipment::class);
        $storefrontParcel = $shipment->order->source === OrderSource::Website && $shipment->status !== OrderCourierStatus::Cancelled;

        return [
            'shipment' => $shipment->public_id,
            'reference' => $shipment->reference,
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_tone' => $status->tone(),
            'message' => $current === null && $shipment->label_blocker !== null
                ? __("shipping_label.refused.{$shipment->label_blocker}")
                : null,
            'version' => $current?->version,
            'issued_at' => $current?->issued_at->toIso8601String(),
            'print_url' => route('admin.shipments.label.show', $shipment->public_id),
            'issue_url' => route('admin.shipments.label.store', $shipment->public_id),
            'reissue_url' => route('admin.shipments.label.reissue', $shipment->public_id),
            'can' => [
                'print' => $status->isPrintable(),
                'issue' => $manage && $storefrontParcel && $current === null,
                'reissue' => $manage && $storefrontParcel && $current !== null,
            ],
            'versions' => $withVersions ? $this->versions($shipment) : [],
        ];
    }

    /**
     * The order's parcels, each with whether its label can be printed and,
     * when it can, the Partner's own print page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forPartner(Order $order): array
    {
        return Shipment::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', OrderCourierStatus::Cancelled->value)
            ->with(['currentLabel:id,shipment_id,version,parcel_number,parcel_count', 'order:id,source'])
            ->orderBy('id')
            ->get()
            ->map(function (Shipment $shipment) use ($order): array {
                $current = $shipment->currentLabel;
                $ready = ShipmentLabelStatus::of($shipment, $current)->isPrintable();

                return [
                    'shipment' => $shipment->public_id,
                    'reference' => $shipment->reference,
                    'ready' => $ready,
                    'parcel' => $current === null ? null : __('shipping_label.parcel_of', [
                        'number' => $current->parcel_number,
                        'count' => $current->parcel_count,
                    ]),
                    'print_url' => $ready ? route('store-orders.shipments.label', [$order->public_id, $shipment->public_id]) : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function versions(Shipment $shipment): array
    {
        return ShipmentLabel::query()
            ->where('shipment_id', $shipment->id)
            ->with('issuedBy:id,name')
            ->orderBy('version')
            ->get()
            ->map(fn (ShipmentLabel $label): array => [
                'version' => $label->version,
                'issued_at' => $label->issued_at->toIso8601String(),
                'issued_by' => $label->issuedBy?->name,
                'issue_reason' => $label->issue_reason,
                'superseded_at' => $label->superseded_at?->toIso8601String(),
                'superseded_reason' => $label->superseded_reason,
                'print_url' => route('admin.shipments.label.show', [$shipment->public_id, 'version' => $label->version]),
            ])
            ->values()
            ->all();
    }
}
