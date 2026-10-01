<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Supplier\Actions\RemoveSupplierListingLotItem;
use App\Domain\Supplier\Actions\SaveSupplierListingDraft;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use App\Http\Controllers\Controller;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A single product entry inside a Supplier's listing lot (Supplier Bulk
 * Product Listing batch).
 *
 * A product entry is an ordinary {@see SupplierProductListing} row -- this
 * controller is the lot workspace's door to the same
 * {@see SaveSupplierListingDraft} action the single-listing form already
 * uses, one product at a time, so every field-level rule (money at the HTTP
 * boundary, one-value-per-attribute, editable-status gating) is enforced in
 * exactly one place regardless of which screen a Supplier used. Only the
 * product's own fields and its variants travel through `store`/`update` --
 * images go through the existing {@see ListingMediaController}, unchanged,
 * since a lot's product entry and a standalone listing share the same media
 * routes and ownership rules.
 *
 * Never platform_rate: a Supplier only ever enters {@see
 * SupplierProductListingItem::$supplier_rate} here, never the staff-only
 * margin {@see DecideSupplierListing::approveItem()} sets at approval.
 */
class ListingLotItemController extends Controller
{
    public function store(Request $request, string $lot, SaveSupplierListingDraft $save): RedirectResponse
    {
        $supplier = $this->supplier($request);
        $model = $this->ownLot($request, $lot);

        [$attributes, $items] = $this->validated($request);

        try {
            $save->handle($supplier, $attributes, $items, lot: $model);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['entry' => $e->getMessage()]);
        }

        return back()->with('success', 'Product entry added.');
    }

    public function update(Request $request, string $lot, string $item, SaveSupplierListingDraft $save): RedirectResponse
    {
        $supplier = $this->supplier($request);
        $lotModel = $this->ownLot($request, $lot);
        $entry = $this->ownEntry($lotModel, $item);

        [$attributes, $items] = $this->validated($request);

        try {
            $save->handle($supplier, $attributes, $items, listing: $entry, lot: $lotModel);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['entry' => $e->getMessage()]);
        }

        return back()->with('success', 'Product entry updated.');
    }

    public function destroy(Request $request, string $lot, string $item, RemoveSupplierListingLotItem $remove): RedirectResponse
    {
        $supplier = $this->supplier($request);
        $lotModel = $this->ownLot($request, $lot);
        $entry = $this->ownEntry($lotModel, $item);

        try {
            $remove->handle($supplier, $entry);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['entry' => $e->getMessage()]);
        }

        return back()->with('success', 'Product entry removed.');
    }

    protected function supplier(Request $request): Supplier
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        return $supplier;
    }

    protected function ownLot(Request $request, string $publicId): SupplierProductListingLot
    {
        return $this->supplier($request)->lots()->where('public_id', $publicId)->firstOrFail();
    }

    protected function ownEntry(SupplierProductListingLot $lot, string $publicId): SupplierProductListing
    {
        return $lot->items()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'string'],
            'category_suggestion' => ['nullable', 'string', 'max:255'],
            'brand_id' => ['nullable', 'string'],
            'brand_suggestion' => ['nullable', 'string', 'max:255'],
            'supplier_note' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'string'],
            'items.*.variant_label' => ['nullable', 'string', 'max:255'],
            'items.*.supplier_sku' => ['required', 'string', 'max:100'],

            // Entered in Taka; parsed into a Money instance below, at this
            // HTTP boundary (§36.1) -- never a platform_rate field here.
            'items.*.supplier_rate' => ['required', new DecimalAmountRule],
            'items.*.currency_code' => ['nullable', 'string', 'size:3'],

            'items.*.supply_mode' => ['nullable', Rule::in(['ready_stock', 'on_demand', 'pre_order'])],
            // Stock is never mandatory (a null value is "not declared", not
            // zero) -- only ready_stock is even expected to carry one.
            'items.*.available_quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.minimum_supply_quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.lead_time_days' => ['nullable', 'integer', 'min:0'],
            'items.*.expected_availability_at' => ['nullable', 'date'],
            'items.*.fulfilment_capacity' => ['nullable', 'integer', 'min:0'],
            'items.*.warranty' => ['nullable', 'string', 'max:255'],
            'items.*.return_conditions' => ['nullable', 'string', 'max:1000'],

            'items.*.attribute_value_ids' => ['nullable', 'array'],
            'items.*.attribute_value_ids.*' => ['string'],
        ]);

        $items = array_values(array_map(function (array $item) {
            $currency = isset($item['currency_code'])
                ? Currency::from($item['currency_code'])
                : Currency::base();

            $item['supplier_rate'] = DecimalAmount::parse($item['supplier_rate'], $currency);

            return $item;
        }, $data['items']));

        unset($data['items']);

        return [$data, $items];
    }
}
