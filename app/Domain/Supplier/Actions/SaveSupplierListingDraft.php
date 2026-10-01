<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Supplier\Enums\ListingItemStatus;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Creates or edits a Supplier's own Product Listing Request while it is still
 * editable (D25, P13-9).
 *
 * Writing here never touches `products`, `product_variants`, or
 * `supplier_offers` — a listing is a proposal until an Admin decides it
 * (§12-equivalent). Only {@see SubmitSupplierListing} moves it out of reach of
 * this action.
 *
 * Items are **synchronised in place**, matched by their public id, rather than
 * deleted and recreated: the database refuses to delete an item once its
 * listing has been submitted, and an item a reviewer has already approved or
 * rejected is part of a decision that must not be edited away. A draft may
 * drop a variation; a listing sent back for correction may only fix or add.
 */
class SaveSupplierListingDraft
{
    public function __construct(
        protected DatabaseManager $database,
        protected ManageSupplierListingItemAttributes $manageAttributes,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $items
     */
    public function handle(
        Supplier $supplier,
        array $attributes,
        array $items,
        ?SupplierProductListing $listing = null,
        ?SupplierProductListingLot $lot = null,
    ): SupplierProductListing {
        return $this->database->transaction(function () use ($supplier, $attributes, $items, $listing, $lot) {
            if ($listing !== null) {
                if ($listing->supplier_id !== $supplier->id) {
                    throw new InvalidArgumentException('This listing does not belong to this Supplier.');
                }

                if (! $listing->isEditableBySupplier()) {
                    throw new InvalidArgumentException('This listing is being reviewed and cannot be changed.');
                }
            }

            if ($lot !== null && $lot->supplier_id !== $supplier->id) {
                throw new InvalidArgumentException('This lot does not belong to this Supplier.');
            }

            $fields = $this->fields($attributes);

            if ($listing === null) {
                // New product entries may only be added while the lot is
                // still Draft -- once submitted, every item it already held
                // is what staff is reviewing (SupplierProductListingLot::rollupStatus()
                // relies on this to never see a stray Draft item mixed into
                // an already-decided lot).
                if ($lot !== null && $lot->status !== LotStatus::Draft) {
                    throw new InvalidArgumentException('New product entries can only be added while the lot is still a draft.');
                }

                $listing = $supplier->listings()->create([...$fields, 'lot_id' => $lot?->id]);
            } else {
                $listing->fill($fields)->save();
            }

            $existing = $listing->items()->get()->keyBy('public_id');
            $kept = [];

            foreach ($items as $item) {
                $model = filled($item['id'] ?? null) ? $existing->get($item['id']) : null;

                if ($model === null) {
                    $model = $listing->items()->create($this->itemFields($item));
                    $kept[] = $model->id;

                    if (array_key_exists('attribute_value_ids', $item)) {
                        $this->manageAttributes->handle($supplier, $model, $this->attributeValueIds($item));
                    }

                    continue;
                }

                $kept[] = $model->id;

                // Decided items are part of a review outcome; leave them be.
                if (in_array($model->status, [ListingItemStatus::Approved, ListingItemStatus::Rejected], true)) {
                    continue;
                }

                $model->fill($this->itemFields($item))->save();

                if (array_key_exists('attribute_value_ids', $item)) {
                    $this->manageAttributes->handle($supplier, $model, $this->attributeValueIds($item));
                }
            }

            // A variation may be edited or dropped while its listing is a
            // draft *or* has been sent back for correction (the batch's own
            // spec: "Edit/remove entries while Draft or Returned") -- the
            // widened `feriwala_supplier_listing_items_deletable_only_in_draft`
            // guard permits exactly this pair at the database level too.
            if (in_array($listing->status, [ListingStatus::Draft, ListingStatus::CorrectionRequired], true)) {
                $listing->items()->whereNotIn('id', $kept)->delete();
            }

            return $listing->refresh()->load('items');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function fields(array $attributes): array
    {
        return [
            'product_name' => $attributes['product_name'],
            'description' => $attributes['description'] ?? null,
            'category_id' => filled($attributes['category_id'] ?? null)
                ? Category::query()->where('public_id', $attributes['category_id'])->value('id')
                : null,
            'category_suggestion' => $attributes['category_suggestion'] ?? null,
            'brand_id' => filled($attributes['brand_id'] ?? null)
                ? Brand::query()->where('public_id', $attributes['brand_id'])->value('id')
                : null,
            'brand_suggestion' => $attributes['brand_suggestion'] ?? null,
            'supplier_note' => $attributes['supplier_note'] ?? null,
            'images' => $attributes['images'] ?? null,
            'documents' => $attributes['documents'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function itemFields(array $item): array
    {
        return [
            'variant_label' => $item['variant_label'] ?? null,
            'supplier_sku' => $item['supplier_sku'],
            'supplier_rate' => $item['supplier_rate'],
            'currency_code' => $item['currency_code'] ?? 'BDT',
            // Stock is never mandatory: an absent value stays absent, never
            // coerced to zero (which would read as "confirmed no stock") or
            // to some other guess.
            'available_quantity' => isset($item['available_quantity'])
                ? (int) $item['available_quantity']
                : null,
            'minimum_supply_quantity' => (int) ($item['minimum_supply_quantity'] ?? 1),
            'lead_time_days' => $item['lead_time_days'] ?? null,
            'warranty' => $item['warranty'] ?? null,
            'return_conditions' => $item['return_conditions'] ?? null,
            'supply_mode' => $item['supply_mode'] ?? SupplyMode::ReadyStock->value,
            'fulfilment_capacity' => $item['fulfilment_capacity'] ?? null,
            'expected_availability_at' => $item['expected_availability_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    protected function attributeValueIds(array $item): array
    {
        return array_values(array_map(strval(...), (array) $item['attribute_value_ids']));
    }
}
