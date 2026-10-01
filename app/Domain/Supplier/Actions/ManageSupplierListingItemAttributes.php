<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Catalog\Actions\ManageVariants;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierProductListingItem;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Sets the structured option values a Supplier's proposed variation carries
 * -- Size, Colour, Material, Style (Supplier Bulk Product Listing batch).
 *
 * A Supplier only ever *selects* from the catalogue's own existing
 * {@see ProductAttributeValue} rows, mirroring how
 * {@see ManageVariants::create()} enforces one
 * value per attribute for a real {@see ProductVariant}
 * -- this never creates an attribute or a value of its own, and the set is
 * always replaced wholesale rather than patched, since it is always fully
 * specified by whatever the workspace form last submitted.
 */
class ManageSupplierListingItemAttributes
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  list<string>  $attributeValuePublicIds
     */
    public function handle(Supplier $supplier, SupplierProductListingItem $variant, array $attributeValuePublicIds): SupplierProductListingItem
    {
        $listing = $variant->listing;

        if ($listing->supplier_id !== $supplier->id) {
            throw new InvalidArgumentException('This variation does not belong to this Supplier.');
        }

        if (! $listing->isEditableBySupplier()) {
            throw new InvalidArgumentException('This listing can no longer be edited.');
        }

        $values = ProductAttributeValue::query()
            ->whereIn('public_id', $attributeValuePublicIds)
            ->get(['id', 'public_id', 'product_attribute_id']);

        if ($values->count() !== count(array_unique($attributeValuePublicIds))) {
            throw new InvalidArgumentException('One or more of the selected option values does not exist.');
        }

        $byAttribute = $values->groupBy('product_attribute_id');

        if ($byAttribute->contains(fn ($group) => $group->count() > 1)) {
            throw new InvalidArgumentException('Only one value may be chosen per attribute -- a shirt is not both M and L.');
        }

        return $this->database->transaction(function () use ($variant, $values) {
            $variant->attributeValues()->sync(
                $values->mapWithKeys(fn (ProductAttributeValue $value) => [
                    $value->id => ['product_attribute_id' => $value->product_attribute_id],
                ])->all(),
            );

            return $variant->refresh();
        });
    }
}
