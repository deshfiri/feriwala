<?php

use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Supplier\Actions\ManageSupplierListingItemAttributes;
use App\Domain\Supplier\Models\Supplier;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Str;

/*
 * Structured variant option groups for a Supplier's proposed variation
 * (Supplier Bulk Product Listing, commit 2 of 5) -- Size, Colour and so on,
 * reusing the catalogue's own attribute/value rows rather than a parallel
 * attribute system.
 */

function supplierListingVariantTestAttribute(string $name, array $values): ProductAttribute
{
    $attribute = ProductAttribute::create(['name' => $name, 'slug' => Str::slug($name)]);

    foreach ($values as $value) {
        $attribute->values()->create(['value' => $value]);
    }

    return $attribute->refresh();
}

it('sets a variation\'s attribute values from the existing catalogue', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A shirt']);
    $variant = $listing->items()->create(['supplier_sku' => 'SKU-1', 'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT)]);

    $size = supplierListingVariantTestAttribute('Size', ['M', 'L']);
    $colour = supplierListingVariantTestAttribute('Colour', ['Navy', 'Red']);

    $m = $size->values->firstWhere('value', 'M');
    $navy = $colour->values->firstWhere('value', 'Navy');

    app(ManageSupplierListingItemAttributes::class)->handle($supplier, $variant, [$m->public_id, $navy->public_id]);

    expect($variant->attributeValues()->pluck('value')->all())->toEqualCanonicalizing(['M', 'Navy']);
});

it('refuses two values for the same attribute', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A shirt']);
    $variant = $listing->items()->create(['supplier_sku' => 'SKU-1', 'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT)]);

    $size = supplierListingVariantTestAttribute('Size', ['M', 'L']);
    [$m, $l] = [$size->values->firstWhere('value', 'M'), $size->values->firstWhere('value', 'L')];

    expect(fn () => app(ManageSupplierListingItemAttributes::class)->handle($supplier, $variant, [$m->public_id, $l->public_id]))
        ->toThrow(InvalidArgumentException::class);

    expect($variant->attributeValues()->count())->toBe(0);
});

it('refuses an attribute value that does not exist', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A shirt']);
    $variant = $listing->items()->create(['supplier_sku' => 'SKU-1', 'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT)]);

    expect(fn () => app(ManageSupplierListingItemAttributes::class)->handle($supplier, $variant, ['not-a-real-public-id']))
        ->toThrow(InvalidArgumentException::class);
});

it('replaces the attribute set wholesale on resubmission', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A shirt']);
    $variant = $listing->items()->create(['supplier_sku' => 'SKU-1', 'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT)]);

    $size = supplierListingVariantTestAttribute('Size', ['M', 'L']);
    [$m, $l] = [$size->values->firstWhere('value', 'M'), $size->values->firstWhere('value', 'L')];

    app(ManageSupplierListingItemAttributes::class)->handle($supplier, $variant, [$m->public_id]);
    app(ManageSupplierListingItemAttributes::class)->handle($supplier, $variant, [$l->public_id]);

    expect($variant->attributeValues()->pluck('value')->all())->toBe(['L']);
});

it('refuses another supplier\'s variation', function () {
    $owner = Supplier::factory()->create();
    $listing = $owner->listings()->create(['product_name' => 'A shirt']);
    $variant = $listing->items()->create(['supplier_sku' => 'SKU-1', 'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT)]);
    $stranger = Supplier::factory()->create();

    expect(fn () => app(ManageSupplierListingItemAttributes::class)->handle($stranger, $variant, []))
        ->toThrow(InvalidArgumentException::class);
});
