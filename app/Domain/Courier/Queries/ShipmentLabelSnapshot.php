<?php

namespace App\Domain\Courier\Queries;

use App\Domain\Courier\Exceptions\ShipmentLabelRefused;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\ShipmentLabelLogos;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Website\Models\Website;
use App\Support\Barcode\Code128;
use App\Support\Localization\MobileNumber;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * What a parcel's label says, worked out once and frozen by the action that
 * issues it.
 *
 * **Every part comes from something already frozen.** The recipient, address
 * and note are the order's own placement snapshot (never a live customer
 * record); the Storefront is the order's locked `website_id` (never whoever is
 * signed in); the courier identifiers and COD amount are the shipment's; the
 * sender and return address are the Banij warehouse the parcel left from.
 *
 * **Two blocks, never mixed.** The Storefront's name, logo and phone tell the
 * customer who they bought from. The return address is *always* the fulfilling
 * Banij warehouse: no Storefront contact address, Partner address or account
 * address is read here at all.
 *
 * **Only what delivery needs.** No email, no Partner figure, no cost, profit,
 * receivable, payment detail, internal note or audit data.
 *
 * Refuses with a safe code rather than issue a label a courier could misread.
 */
class ShipmentLabelSnapshot
{
    /** Usable barcode width on the narrower (3 inch) side, in inches. */
    public const BARCODE_MAX_WIDTH = 2.8;

    public function __construct(
        protected ResolveShipmentWarehouse $warehouses,
        protected ShipmentLabelLogos $logos,
        protected MobileNumber $numbers,
    ) {}

    /**
     * @return array<string, mixed> the label's columns, apart from its version and issue metadata
     *
     * @throws ShipmentLabelRefused
     */
    public function build(Shipment $shipment): array
    {
        $shipment->loadMissing(['order.website', 'order.items', 'order.payment', 'courierProvider', 'packages']);

        $order = $shipment->order;

        if ($order->source !== OrderSource::Website) {
            throw ShipmentLabelRefused::because('not_storefront_order');
        }

        $website = $order->website;

        if (! $website instanceof Website) {
            throw ShipmentLabelRefused::because('storefront_missing');
        }

        if ($shipment->status === OrderCourierStatus::Cancelled) {
            throw ShipmentLabelRefused::because('shipment_cancelled');
        }

        $barcode = $this->barcodeValue($shipment);
        [$recipientName, $recipientPhone, $address] = $this->recipient($order);
        $warehouse = $this->warehouse($shipment, $order);
        [$cod, $prepaid] = $this->cashOnDelivery($shipment, $order);
        [$parcelNumber, $parcelCount, $sameWarehouseParcels] = $this->parcel($shipment);

        return [
            'shipment_id' => $shipment->id,
            'order_id' => $order->id,
            'website_id' => $website->id,
            'warehouse_id' => $warehouse->id,

            'courier_provider_code' => $shipment->courierProvider->code->value,
            'courier_provider_name' => $shipment->courierProvider->name,
            'consignment_id' => $this->clean($shipment->external_id),
            'tracking_code' => $this->clean($shipment->tracking_number),
            'barcode_value' => $barcode,

            'store_name' => trim($website->name),
            'store_phone' => $this->clean($website->contact_phone),
            'store_accent_color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $website->primary_color) === 1 ? $website->primary_color : null,
            ...($this->logos->freeze($website) ?? ['store_logo_disk' => null, 'store_logo_path' => null, 'store_logo_mime' => null]),

            'recipient_name' => $recipientName,
            'recipient_phone' => $recipientPhone,
            'delivery_address' => $address,
            'delivery_instructions' => $this->clean($order->customer_note),
            'currency_code' => $cod->currency->value,
            'cod_amount' => $cod,
            'is_prepaid' => $prepaid,
            'parcel_number' => $parcelNumber,
            'parcel_count' => $parcelCount,
            'package_count' => max(1, $shipment->packages->count()),
            'item_quantity' => $this->itemQuantity($order, $warehouse, $parcelCount, $sameWarehouseParcels),
            'items' => $parcelCount === 1 ? $this->items($order) : null,
            'parcel_weight_kg' => $this->weight($shipment),
            'order_reference' => $order->reference,
            'storefront_order_reference' => $this->clean($order->storefront_order_reference),
            'order_placed_at' => $order->placed_at,

            'warehouse_code' => $warehouse->code,
            'warehouse_name' => $warehouse->name,
            'warehouse_contact_name' => trim((string) $warehouse->courier_contact_name),
            'warehouse_phone' => trim((string) $warehouse->courier_phone),
            'warehouse_return_address' => $warehouse->courierReturnAddress(),
        ];
    }

    /**
     * The identifier the courier scans: its tracking code, else its
     * consignment id. Never a URL, a token or anything of Banij's own.
     *
     * @throws ShipmentLabelRefused
     */
    protected function barcodeValue(Shipment $shipment): string
    {
        $candidates = array_values(array_filter([
            $this->clean($shipment->tracking_number),
            $this->clean($shipment->external_id),
        ]));

        if ($candidates === []) {
            throw ShipmentLabelRefused::because('waiting_for_courier');
        }

        foreach ($candidates as $candidate) {
            if (Code128::canEncode($candidate) && Code128::moduleDots($candidate, self::BARCODE_MAX_WIDTH) !== null) {
                return $candidate;
            }
        }

        throw ShipmentLabelRefused::because('barcode_unencodable');
    }

    /**
     * The order's own frozen recipient: the same fields, in the same order of
     * preference, the courier booking itself was built from.
     *
     * @return array{0: string, 1: string, 2: array<string, string|null>}
     *
     * @throws ShipmentLabelRefused
     */
    protected function recipient(Order $order): array
    {
        $customer = $order->customer;
        $address = $order->shipping_address ?? [];

        $name = $this->first([$customer['name'] ?? null, $customer['contact_name'] ?? null, $address['contact_name'] ?? null]);
        $mobile = $this->first([$customer['mobile'] ?? null, $address['contact_mobile'] ?? null, $customer['phone'] ?? null]);
        $line1 = $this->first([$address['line1'] ?? null, $address['line_1'] ?? null, $address['detailed_address'] ?? null]);

        if ($name === null || $mobile === null || $line1 === null) {
            throw ShipmentLabelRefused::because('recipient_incomplete');
        }

        return [$name, $this->displayPhone($mobile), [
            'line1' => $line1,
            'line2' => $this->first([$address['line2'] ?? null, $address['line_2'] ?? null]),
            'landmark' => $this->first([$address['landmark'] ?? null]),
            'area' => $this->first([$address['area'] ?? null, $address['union'] ?? null]),
            'upazila' => $this->first([$address['upazila'] ?? null]),
            'city' => $this->first([$address['city'] ?? null]),
            'district' => $this->first([$address['district'] ?? null]),
            'division' => $this->first([$address['division'] ?? null]),
            'postcode' => $this->first([$address['postcode'] ?? null]),
        ]];
    }

    /**
     * The Banij warehouse the parcel left from: the one fixed on the shipment,
     * or the order's single warehouse. Refused -- never guessed, never a
     * Storefront address -- when there is not exactly one, or it is not
     * approved with complete return details.
     *
     * @throws ShipmentLabelRefused
     */
    protected function warehouse(Shipment $shipment, Order $order): Warehouse
    {
        $warehouseId = $shipment->warehouse_id;

        if ($warehouseId === null) {
            $resolution = $this->warehouses->handle($order);
            $refusal = $resolution->refusal();

            if ($refusal !== null) {
                throw ShipmentLabelRefused::because($refusal);
            }

            $warehouseId = $resolution->single();
        }

        $warehouse = $warehouseId === null ? null : Warehouse::query()->find($warehouseId);

        if (! $warehouse instanceof Warehouse) {
            throw ShipmentLabelRefused::because('warehouse_undetermined');
        }

        if (! $warehouse->isUsableForCourierLabels()) {
            throw ShipmentLabelRefused::because('warehouse_not_ready');
        }

        return $warehouse;
    }

    /**
     * What the courier collects. A COD order the shipment recorded no amount
     * for is refused: printing "Paid" on a parcel that owes money is the one
     * mistake this must never make.
     *
     * @return array{0: Money, 1: bool}
     *
     * @throws ShipmentLabelRefused
     */
    protected function cashOnDelivery(Shipment $shipment, Order $order): array
    {
        $cod = $shipment->cod_amount;

        if ($cod === null) {
            if ($order->isCashOnDelivery() && $order->paid_at === null) {
                throw ShipmentLabelRefused::because('cod_unknown');
            }

            $cod = Money::zero(Currency::from($shipment->currency_code));
        }

        return [$cod, $cod->isZero()];
    }

    /**
     * "Parcel N of M" across the order's live shipments, oldest first, and how
     * many of them leave from the same warehouse as this one.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    protected function parcel(Shipment $shipment): array
    {
        $live = Shipment::query()
            ->where('order_id', $shipment->order_id)
            ->where('status', '!=', OrderCourierStatus::Cancelled->value)
            ->orderBy('id')
            ->get(['id', 'warehouse_id']);

        $position = $live->search(fn (Shipment $candidate) => $candidate->id === $shipment->id);

        $sameWarehouse = $shipment->warehouse_id === null
            ? $live->count()
            : $live->where('warehouse_id', $shipment->warehouse_id)->count();

        return [$position === false ? 1 : $position + 1, max(1, $live->count()), $sameWarehouse];
    }

    /**
     * How many units are in this parcel, only when that can be stated exactly:
     * the whole order when it is the only parcel, or the warehouse's share when
     * it is the only parcel from that warehouse. Otherwise null, and the label
     * leaves the line out rather than print a guess.
     */
    protected function itemQuantity(Order $order, Warehouse $warehouse, int $parcelCount, int $sameWarehouseParcels): ?int
    {
        if ($parcelCount === 1) {
            return (int) $order->items->sum('quantity');
        }

        $resolution = $this->warehouses->handle($order);

        return $sameWarehouseParcels === 1 && $resolution->includes($warehouse->id)
            ? $resolution->quantityFrom($warehouse->id)
            : null;
    }

    /**
     * What the parcel holds, line by line, when it is the order's only parcel:
     * the order's current lines as placed (or as last revised). Names, never
     * prices, costs or stock.
     *
     * @return list<array{name: string, variant: string|null, sku: string, quantity: int}>
     */
    protected function items(Order $order): array
    {
        return array_values($order->items
            ->sortBy('line_number')
            ->map(fn (OrderItem $line): array => [
                'name' => trim($line->product_name),
                'variant' => $this->clean($line->variant_label),
                'sku' => $line->sku,
                'quantity' => (int) $line->quantity,
            ])
            ->all());
    }

    /**
     * The parcel's weight in kg as recorded on its packages, only when every
     * package has one. Never estimated: a label shows no weight rather than a
     * guess.
     */
    protected function weight(Shipment $shipment): ?string
    {
        if ($shipment->packages->isEmpty()) {
            return null;
        }

        $total = '0.000';

        foreach ($shipment->packages as $package) {
            $weight = (string) $package->weight;

            if (! is_numeric($weight) || bccomp($weight, '0', 3) <= 0) {
                return null;
            }

            $total = bcadd($total, $weight, 3);
        }

        return $total;
    }

    /**
     * A Bangladeshi mobile as couriers write it, `01XXXXXXXXX`; anything else
     * exactly as the order recorded it.
     */
    protected function displayPhone(string $mobile): string
    {
        $e164 = $this->numbers->normalise($mobile);

        if ($e164 !== null && preg_match('/^\+8801\d{9}$/', $e164) === 1) {
            return '0'.substr($e164, 4);
        }

        return $mobile;
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    protected function first(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    protected function clean(?string $value): ?string
    {
        return $this->first([$value]);
    }
}
