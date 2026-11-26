<?php

namespace App\Domain\Courier\Queries;

use App\Domain\Courier\Enums\ShipmentLabelSize;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Courier\ShipmentLabelLogos;
use App\Support\Barcode\Code128;
use App\Support\Barcode\QrCode;
use Carbon\CarbonImmutable;

/**
 * One frozen label version, laid out for one label size -- what the print view
 * renders, and nothing it may not.
 *
 * Read only from the label's own columns: nothing here looks at the live
 * Storefront, order, customer or warehouse, so a reprint shows exactly what was
 * issued. The one value that is not frozen is when it was printed. Text arrives
 * unescaped and is escaped by the view; the barcode and QR code are geometry
 * only, both encoding the courier's own identifier.
 */
class ShipmentLabelDocument
{
    public function __construct(
        protected ShipmentLabelLogos $logos,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(ShipmentLabel $label, ShipmentLabelSize $size, ?CarbonImmutable $printedAt = null): array
    {
        [$address, $area] = $this->addressParts($label->delivery_address);
        $addressLength = mb_strlen($address.' '.$area);

        return [
            'id' => $label->public_id,
            'version' => $label->version,
            'detailed' => $size->isDetailed(),

            'store' => [
                'name' => $label->store_name,
                // A long name is set smaller rather than cut short.
                'long_name' => mb_strlen($label->store_name) > 28,
                'logo' => $this->logos->dataUri($label),
            ],
            'parcel' => __('shipping_label.parcel_of', ['number' => $label->parcel_number, 'count' => $label->parcel_count]),

            'courier' => $label->courier_provider_name,
            'consignment_id' => $label->consignment_id,
            'tracking_code' => $label->tracking_code,
            'barcode_value' => $label->barcode_value,
            'barcode_svg' => Code128::svg($label->barcode_value, ShipmentLabelSnapshot::BARCODE_MAX_WIDTH, $size->barcodeHeightInches()),
            'qr_svg' => QrCode::svg($label->barcode_value, $size->qrMaxInches()),
            // The smallest it may be drawn when the label is short of room: still whole dots.
            'qr_min_inches' => sprintf('%.4F', QrCode::inchesAt($label->barcode_value, QrCode::MIN_MODULE_DOTS)),

            'order_reference' => $label->order_reference,
            'storefront_reference' => $label->storefront_order_reference,
            'delivery_type' => __('shipping_label.delivery_home'),
            'weight' => $this->weight($label->parcel_weight_kg),

            'recipient' => [
                'name' => $label->recipient_name,
                'phone' => $label->recipient_phone,
                'address' => $address,
                'area' => $area,
            ],
            // Long addresses start a step or two smaller; the page steps further
            // once the fonts are in. An address is never cut short.
            'fit' => $this->fit($addressLength, $size),
            'instructions' => $label->delivery_instructions,

            'items' => $this->items($label, $size),

            'payment' => $label->is_prepaid
                ? ['prepaid' => true, 'caption' => __('shipping_label.prepaid'), 'amount' => __('shipping_label.nothing_due', ['amount' => $label->cod_amount->format()])]
                : ['prepaid' => false, 'caption' => __('shipping_label.cash_on_delivery'), 'amount' => $label->cod_amount->format()],

            'warehouse' => [
                'name' => $label->warehouse_name,
                'contact' => $label->warehouse_contact_name,
                'phone' => $label->warehouse_phone,
                'address' => implode(', ', array_filter([
                    $label->warehouse_return_address['line'] ?? null,
                    $label->warehouse_return_address['area'] ?? null,
                    trim(($label->warehouse_return_address['district'] ?? '').' '.($label->warehouse_return_address['postcode'] ?? '')),
                ])),
            ],

            'printed_at' => ($printedAt ?? CarbonImmutable::now())->timezone(config('app.timezone'))->format('d/m/y g:ia'),
        ];
    }

    /**
     * The delivery address as printed, in two parts: the street-level address
     * (lines, landmark, area or union), then the administrative area (upazila,
     * city, district with postcode, division) -- with nothing said twice.
     *
     * @param  array<string, string|null>  $address
     * @return array{0: string, 1: string}
     */
    protected function addressParts(array $address): array
    {
        $street = array_values(array_unique(array_filter([
            $address['line1'] ?? null,
            $address['line2'] ?? null,
            $address['landmark'] ?? null,
            $address['area'] ?? null,
        ])));

        $districtName = $address['district'] ?? null;
        // A city or division that is also the district is said once, with the district.
        $sameAsDistrict = fn (?string $part): ?string => $part !== null && $part === $districtName ? null : $part;

        $area = array_filter([
            $address['upazila'] ?? null,
            $sameAsDistrict($address['city'] ?? null),
            trim(($districtName ?? '').' '.($address['postcode'] ?? '')),
            $sameAsDistrict($address['division'] ?? null),
        ]);

        // A part the street address already names is not repeated.
        $area = array_values(array_unique(array_diff($area, $street)));

        return [implode(', ', $street), implode(', ', $area)];
    }

    /**
     * What is in the parcel. Each line on its own up to the size's limit, the
     * rest counted; the total units always when known. A one-line summary is
     * there too, for when the label needs the room. Labels issued before item
     * lines were frozen show the total only.
     *
     * @return array{lines: list<string>, more: int, summary: list<string>, summary_more: int, count: int, units: int|null}
     */
    protected function items(ShipmentLabel $label, ShipmentLabelSize $size): array
    {
        $lines = [];

        foreach ($label->items ?? [] as $item) {
            $name = $item['variant'] !== null && $item['variant'] !== '' ? "{$item['name']} ({$item['variant']})" : $item['name'];
            $lines[] = __('shipping_label.item_line', ['name' => $name, 'quantity' => $item['quantity']]);
        }

        return [
            'lines' => array_slice($lines, 0, $size->itemLines()),
            'more' => max(0, count($lines) - $size->itemLines()),
            'summary' => array_slice($lines, 0, 1),
            'summary_more' => max(0, count($lines) - 1),
            'count' => count($lines),
            'units' => $label->item_quantity,
        ];
    }

    /**
     * "1.04 kg" from the frozen decimal string; never converted through a float.
     */
    protected function weight(?string $kilograms): ?string
    {
        if ($kilograms === null) {
            return null;
        }

        $trimmed = str_contains($kilograms, '.') ? rtrim(rtrim($kilograms, '0'), '.') : $kilograms;

        return __('shipping_label.weight_kg', ['weight' => $trimmed]);
    }

    /**
     * The fitting level a label starts at, from its address length alone. The
     * print page raises it further, one level at a time, if the rendered text
     * still does not fit.
     */
    protected function fit(int $length, ShipmentLabelSize $size): int
    {
        [$tight, $tighter] = $size->isDetailed() ? [150, 230] : [80, 130];

        return match (true) {
            $length > $tighter => 2,
            $length > $tight => 1,
            default => 0,
        };
    }
}
