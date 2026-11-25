<?php

namespace App\Domain\Courier\Queries;

use App\Domain\Courier\Enums\ShipmentLabelSize;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Courier\ShipmentLabelLogos;
use App\Support\Barcode\Code128;

/**
 * One frozen label version, laid out for one label size -- what the print view
 * renders, and nothing it may not.
 *
 * Read only from the label's own columns: nothing here looks at the live
 * Storefront, order, customer or warehouse, so a reprint shows exactly what was
 * issued. Text arrives unescaped and is escaped by the view; the barcode is
 * geometry only.
 */
class ShipmentLabelDocument
{
    public function __construct(
        protected ShipmentLabelLogos $logos,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(ShipmentLabel $label, ShipmentLabelSize $size): array
    {
        $address = $this->addressLines($label->delivery_address);
        $addressLength = mb_strlen(implode(' ', $address));

        return [
            'id' => $label->public_id,
            'version' => $label->version,
            'detailed' => $size->isDetailed(),

            'store' => [
                'name' => $label->store_name,
                'phone' => $label->store_phone,
                'logo' => $this->logos->dataUri($label),
                'accent' => $label->store_accent_color,
            ],

            'courier' => $label->courier_provider_name,
            'consignment_id' => $label->consignment_id,
            'tracking_code' => $label->tracking_code,
            'barcode_value' => $label->barcode_value,
            'barcode_svg' => Code128::svg($label->barcode_value, ShipmentLabelSnapshot::BARCODE_MAX_WIDTH, $size->barcodeHeightInches()),

            'recipient' => [
                'name' => $label->recipient_name,
                'phone' => $label->recipient_phone,
                'address' => $address,
            ],
            // Long addresses step down in size before anything is clipped.
            'address_fit' => $this->fit($addressLength, $size),
            'instructions' => $label->delivery_instructions,

            'payment' => $label->is_prepaid
                ? ['prepaid' => true, 'text' => __('shipping_label.paid')]
                : ['prepaid' => false, 'text' => __('shipping_label.cod', ['amount' => $label->cod_amount->format()])],

            'parcel' => $label->parcel_count > 1
                ? __('shipping_label.parcel_of', ['number' => $label->parcel_number, 'count' => $label->parcel_count])
                : null,
            'packages' => $label->package_count,
            'items' => $label->item_quantity,
            'order_reference' => $label->order_reference,
            'storefront_reference' => $label->storefront_order_reference,
            'order_date' => $label->order_placed_at->timezone(config('app.timezone'))->format('d M Y'),

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
        ];
    }

    /**
     * The delivery address as printed: street lines first, then the area,
     * upazila and city, then district and postcode -- with nothing repeated.
     *
     * @param  array<string, string|null>  $address
     * @return list<string>
     */
    protected function addressLines(array $address): array
    {
        $street = array_filter([$address['line1'] ?? null, $address['line2'] ?? null, $address['landmark'] ?? null]);
        $locality = array_unique(array_filter([$address['area'] ?? null, $address['upazila'] ?? null, $address['city'] ?? null]));
        $district = array_filter([$address['district'] ?? null, $address['postcode'] ?? null]);

        // A city that is also the district is said once, with the district.
        $locality = array_values(array_diff($locality, [$address['district'] ?? null]));

        return array_values(array_filter([
            implode(', ', $street),
            implode(', ', $locality),
            implode(' ', $district),
        ]));
    }

    protected function fit(int $length, ShipmentLabelSize $size): string
    {
        [$tight, $tighter] = $size->isDetailed() ? [150, 230] : [95, 150];

        return match (true) {
            $length > $tighter => 'tighter',
            $length > $tight => 'tight',
            default => 'normal',
        };
    }
}
