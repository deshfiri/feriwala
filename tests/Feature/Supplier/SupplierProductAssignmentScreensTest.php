<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Supplier\Actions\AssignProductToSupplier;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The screens over the canonical Supplier Offer: staff assigning from the
 * Product, and the Supplier's own "My Products" — both origins together, never
 * another Supplier's, and never the platform's prices.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->supplier = Supplier::factory()->create(['business_name' => 'Alpha Textiles']);
    $this->rival = Supplier::factory()->create(['business_name' => 'Beta Garments']);
    $this->product = Product::create([
        'name' => 'Cotton pants',
        'sku' => 'SS-PANTS',
        'category_id' => Category::query()->firstOrCreate(['name' => 'Menswear'])->id,
        'base_cost' => Money::fromDecimal('1500.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('2000.00', Currency::BDT),
        'suggested_selling_price' => Money::fromDecimal('2500.00', Currency::BDT),
        'status' => ProductStatus::Active,
        'package_scope' => PackageScope::AllPackages,
    ]);
});

function supplierScreensAssign(Supplier $supplier, Product $product, string $price, $staff): SupplierOffer
{
    return app(AssignProductToSupplier::class)->handle($staff, $product, null, $supplier, Money::fromDecimal($price, Currency::BDT));
}

describe('staff assignment over HTTP', function () {
    it('assigns from the Product with a decimal Taka price and shows it on the Product page', function () {
        $this->actingAs($this->staff)->post(route('admin.product-suppliers.store'), [
            'product_id' => $this->product->public_id,
            'supplier_id' => $this->supplier->public_id,
            'base_price' => '1450.50',
            'supply_mode' => 'on_demand',
            'lead_time_days' => 3,
            'reason' => 'Agreed on call',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->get(route('admin.catalog.products.edit', $this->product->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('product_suppliers.offers', 1)
                ->where('product_suppliers.offers.0.supplier.name', 'Alpha Textiles')
                ->where('product_suppliers.offers.0.supplier_base_price.amount', '1450.50')
                ->where('product_suppliers.offers.0.origin', 'admin_assignment')
                ->where('product_suppliers.offers.0.lead_time_days', 3)
                ->where('product_suppliers.can.assign', true));
    });

    it('refuses a missing, zero or over-high price, and a repeat, with an error on the form', function () {
        $this->actingAs($this->staff);
        $payload = ['product_id' => $this->product->public_id, 'supplier_id' => $this->supplier->public_id];

        $this->post(route('admin.product-suppliers.store'), $payload)->assertSessionHasErrors('base_price');
        $this->post(route('admin.product-suppliers.store'), [...$payload, 'base_price' => '0'])->assertSessionHasErrors('assignment');
        $this->post(route('admin.product-suppliers.store'), [...$payload, 'base_price' => '2600.00'])->assertSessionHasErrors('assignment');

        $this->post(route('admin.product-suppliers.store'), [...$payload, 'base_price' => '1400.00'])->assertSessionHasNoErrors();
        $this->post(route('admin.product-suppliers.store'), [...$payload, 'base_price' => '1300.00'])->assertSessionHasErrors('assignment');

        expect(SupplierOffer::query()->count())->toBe(1);
    });

    it('changes only the base price, with a reason, and keeps the platform prices', function () {
        $offer = supplierScreensAssign($this->supplier, $this->product, '1400.00', $this->staff);

        $this->actingAs($this->staff)
            ->patch(route('admin.product-suppliers.price.update', $offer->public_id), ['base_price' => '1350.00', 'reason' => 'Renegotiated'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->patch(route('admin.product-suppliers.price.update', $offer->public_id), ['base_price' => '1300.00'])
            ->assertSessionHasErrors('reason');

        expect($offer->refresh()->supplier_rate->toDecimal())->toBe('1350.00')
            ->and($offer->platform_rate->toDecimal())->toBe('2000.00')
            ->and($this->product->refresh()->wholesale_price->toDecimal())->toBe('2000.00');
    });

    it('is closed to staff without the permissions and hides prices from staff who may not see them', function () {
        $offer = supplierScreensAssign($this->supplier, $this->product, '1400.00', $this->staff);
        $manager = testPlatformStaff(PlatformRole::ProductManager);

        $this->actingAs($manager)->post(route('admin.product-suppliers.store'), [
            'product_id' => $this->product->public_id,
            'supplier_id' => $this->rival->public_id,
            'base_price' => '1400.00',
        ])->assertForbidden();

        $this->patch(route('admin.product-suppliers.price.update', $offer->public_id), ['base_price' => '1000.00', 'reason' => 'x'])
            ->assertForbidden();

        $this->get(route('admin.catalog.products.edit', $this->product->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('product_suppliers.can.view_prices', false)
                ->where('product_suppliers.offers.0.supplier_base_price', null));
    });
});

describe('the Supplier\'s My Products', function () {
    it('lists the approved-listing offers and the assigned ones together, for this Supplier only', function () {
        supplierScreensAssign($this->supplier, $this->product, '1450.00', $this->staff);

        $other = Product::create([
            'name' => 'Cotton shirt', 'sku' => 'SS-SHIRT', 'category_id' => $this->product->category_id,
            'base_cost' => Money::fromDecimal('900.00', Currency::BDT),
            'wholesale_price' => Money::fromDecimal('1200.00', Currency::BDT),
            'status' => ProductStatus::Active, 'package_scope' => PackageScope::AllPackages,
        ]);
        supplierScreensAssign($this->rival, $other, '700.00', $this->staff);

        $this->actingAs($this->supplier, 'supplier')->get(route('supplier.offers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('supplier/offers/index')
                ->has('offers.data', 1)
                ->where('offers.data.0.product_name', 'Cotton pants')
                ->where('offers.data.0.bpc', 'SS-PANTS')
                ->where('offers.data.0.supplier_rate.amount', '1450.00')
                ->where('offers.data.0.origin', 'admin_assignment')
                ->where('offers.data.0.supply_mode', 'ready_stock')
                ->has('offers.data.0', fn (Assert $row) => $row
                    ->missing('platform_rate')
                    ->missing('platform_margin')
                    ->missing('wholesale_price')
                    ->missing('suggested_selling_price')
                    ->etc()));
    });

    it('never opens another Supplier\'s offer', function () {
        $theirs = supplierScreensAssign($this->rival, $this->product, '1300.00', $this->staff);

        $this->actingAs($this->supplier, 'supplier')
            ->get(route('supplier.offers.show', $theirs->public_id))
            ->assertNotFound();
    });

    it('keeps every platform price out of the Supplier\'s detail page', function () {
        $offer = supplierScreensAssign($this->supplier, $this->product, '1450.00', $this->staff);

        $html = $this->actingAs($this->supplier, 'supplier')->get(route('supplier.offers.show', $offer->public_id))
            ->assertOk()->getContent();

        expect($html)->not->toContain('2000.00')->and($html)->not->toContain('2500.00');

        $this->get(route('supplier.offers.show', $offer->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('offer.supplier_rate.amount', '1450.00')
                ->missing('offer.platform_rate')
                ->missing('offer.platform_margin'));
    });
});
