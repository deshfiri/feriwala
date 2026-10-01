<?php

use App\Domain\Supplier\Models\Supplier;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Explicit supply modes and optional stock (Supplier Bulk Product Listing,
 * commit 2 of 5): ready_stock, on_demand, pre_order -- and the CHECK
 * constraints proving each mode's own rules hold at the database level,
 * never only in a form.
 */

/**
 * Postgres aborts the whole transaction on a failed statement; see the
 * identically-named helper in SupplierListingLotSchemaTest.php.
 */
function supplierOfferSupplyModeAssertRefused(Closure $callback): void
{
    try {
        DB::transaction($callback);
        test()->fail('Expected a QueryException to be thrown.');
    } catch (QueryException $exception) {
        expect($exception)->toBeInstanceOf(QueryException::class);
    }
}

it('lets available_quantity be null without forcing the minimum supply quantity down to 1', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A product']);

    // The exact gap found during planning: GREATEST(NULL, 1) = 1 in
    // Postgres, which would have silently rejected -- or worse, silently
    // rewritten -- a stockless item asking for a minimum order quantity
    // above 1 had the constraint not been rewritten to special-case NULL.
    $item = $listing->items()->create([
        'supplier_sku' => 'SKU-1',
        'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT),
        'available_quantity' => null,
        'minimum_supply_quantity' => 5,
        'supply_mode' => 'on_demand',
        'lead_time_days' => 3,
    ]);

    expect($item->fresh()->available_quantity)->toBeNull()
        ->and($item->fresh()->minimum_supply_quantity)->toBe(5);
});

it('refuses a negative available_quantity when one is given', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A product']);

    supplierOfferSupplyModeAssertRefused(fn () => $listing->items()->create([
        'supplier_sku' => 'SKU-1',
        'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT),
        'available_quantity' => -1,
    ]));
});

it('requires a lead time for on_demand', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A product']);

    supplierOfferSupplyModeAssertRefused(fn () => $listing->items()->create([
        'supplier_sku' => 'SKU-1',
        'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT),
        'available_quantity' => null,
        'supply_mode' => 'on_demand',
        'lead_time_days' => null,
    ]));
});

it('requires a lead time or an expected availability date for pre_order', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A product']);

    supplierOfferSupplyModeAssertRefused(fn () => $listing->items()->create([
        'supplier_sku' => 'SKU-1',
        'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT),
        'available_quantity' => null,
        'supply_mode' => 'pre_order',
        'lead_time_days' => null,
        'expected_availability_at' => null,
    ]));

    // Either one alone is enough.
    $item = $listing->items()->create([
        'supplier_sku' => 'SKU-2',
        'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT),
        'available_quantity' => null,
        'supply_mode' => 'pre_order',
        'expected_availability_at' => now()->addWeeks(2),
    ]);

    expect($item->fresh()->supply_mode->value)->toBe('pre_order');
});

it('refuses an unknown supply mode at the database layer', function () {
    // Eloquent's own enum cast would refuse this with a PHP ValueError
    // before a statement ever reaches Postgres -- a raw insert is what
    // proves the CHECK constraint itself, the backstop for any write path
    // that isn't this model.
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A product']);

    supplierOfferSupplyModeAssertRefused(fn () => DB::table('supplier_product_listing_items')->insert([
        'public_id' => (string) Str::ulid(),
        'supplier_product_listing_id' => $listing->id,
        'supplier_sku' => 'SKU-1',
        'supplier_rate_minor' => 50000,
        'currency_code' => 'BDT',
        'available_quantity' => 10,
        'minimum_supply_quantity' => 1,
        'status' => 'pending',
        'supply_mode' => 'backorder',
        'created_at' => now(),
        'updated_at' => now(),
    ]));
});

it('ready_stock needs neither a lead time nor an ETA', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A product']);

    $item = $listing->items()->create([
        'supplier_sku' => 'SKU-1',
        'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT),
        'available_quantity' => 20,
    ]);

    expect($item->fresh()->supply_mode->value)->toBe('ready_stock');
});
