<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Supplier\Actions\AssignProductToSupplier;
use App\Domain\Supplier\Actions\ChangeSupplierBasePrice;
use App\Domain\Supplier\Actions\DecideSupplierListing;
use App\Domain\Supplier\Actions\SuspendSupplierOffer;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\OfferOrigin;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/*
 * One canonical Supplier-Product relationship: the Supplier Offer. Staff
 * assigning a Product and staff approving a listing both produce it, marked
 * with where it came from; the Supplier Base Price is its `supplier_rate`, and
 * the platform's own pricing is read, never rewritten.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->supplier = Supplier::factory()->create(['business_name' => 'Alpha Textiles']);
    $this->product = supplierAssignmentProduct('SA-PANTS');
});

function supplierAssignmentProduct(string $sku, string $wholesale = '2000.00'): Product
{
    return Product::create([
        'name' => 'Cotton pants',
        'sku' => $sku,
        'category_id' => Category::query()->firstOrCreate(['name' => 'Menswear'])->id,
        'base_cost' => Money::fromDecimal('1500.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal($wholesale, Currency::BDT),
        'minimum_selling_price' => Money::fromDecimal('2100.00', Currency::BDT),
        'maximum_selling_price' => Money::fromDecimal('3000.00', Currency::BDT),
        'suggested_selling_price' => Money::fromDecimal('2500.00', Currency::BDT),
        'status' => ProductStatus::Active,
        'package_scope' => PackageScope::AllPackages,
    ]);
}

function supplierAssignmentPrice(string $decimal): Money
{
    return Money::fromDecimal($decimal, Currency::BDT);
}

describe('manual assignment', function () {
    it('creates the canonical offer with the Supplier Base Price, origin and who assigned it', function () {
        $offer = app(AssignProductToSupplier::class)->handle(
            $this->staff,
            $this->product,
            null,
            $this->supplier,
            supplierAssignmentPrice('1450.50'),
            ['supply_mode' => SupplyMode::ReadyStock, 'quantity' => 40, 'lead_time_days' => 2],
        );

        expect($offer->origin)->toBe(OfferOrigin::AdminAssignment)
            ->and($offer->assigned_by)->toBe($this->staff->id)
            ->and($offer->assigned_at)->not->toBeNull()
            ->and($offer->status)->toBe(OfferStatus::Active)
            ->and($offer->supplier_rate->toDecimal())->toBe('1450.50')
            ->and($offer->lead_time_days)->toBe(2)
            ->and($offer->stock->quantity)->toBe(40)
            ->and($offer->priceHistory)->toHaveCount(1)
            ->and(AuditLog::query()->where('action', 'supplier_offer.assigned')->count())->toBe(1);
    });

    it('leaves every platform price on the Product exactly as it was', function () {
        $before = $this->product->only(['wholesale_price', 'minimum_selling_price', 'maximum_selling_price', 'suggested_selling_price']);

        $offer = app(AssignProductToSupplier::class)->handle($this->staff, $this->product, null, $this->supplier, supplierAssignmentPrice('1450.00'));

        $after = $this->product->refresh()->only(['wholesale_price', 'minimum_selling_price', 'maximum_selling_price', 'suggested_selling_price']);

        expect(collect($after)->map->toDecimal()->all())->toBe(collect($before)->map->toDecimal()->all())
            // The offer's platform rate is the wholesale price, read, not edited.
            ->and($offer->platform_rate->toDecimal())->toBe('2000.00');
    });

    it('refuses a missing, zero or negative Supplier Base Price', function (string $price) {
        expect(fn () => app(AssignProductToSupplier::class)->handle($this->staff, $this->product, null, $this->supplier, supplierAssignmentPrice($price)))
            ->toThrow(InvalidArgumentException::class, 'greater than zero');

        expect(SupplierOffer::query()->count())->toBe(0);
    })->with(['zero' => ['0.00']]);

    it('rejects a second active assignment of the same Supplier and Product', function () {
        $assign = app(AssignProductToSupplier::class);
        $assign->handle($this->staff, $this->product, null, $this->supplier, supplierAssignmentPrice('1400.00'));

        expect(fn () => $assign->handle($this->staff, $this->product, null, $this->supplier, supplierAssignmentPrice('1300.00')))
            ->toThrow(InvalidArgumentException::class, 'already assigned');

        expect(SupplierOffer::query()->count())->toBe(1);
    });

    it('asks for the suspended offer to be reactivated rather than copying it', function () {
        $assign = app(AssignProductToSupplier::class);
        $offer = $assign->handle($this->staff, $this->product, null, $this->supplier, supplierAssignmentPrice('1400.00'));
        app(SuspendSupplierOffer::class)->handle($offer, $this->staff->id, 'Paused.');

        expect(fn () => $assign->handle($this->staff, $this->product, null, $this->supplier, supplierAssignmentPrice('1400.00')))
            ->toThrow(InvalidArgumentException::class, 'Reactivate');

        expect(SupplierOffer::query()->count())->toBe(1);
    });

    it('is refused to staff without the permissions, and for a Supplier that is not approved', function () {
        $manager = testPlatformStaff(PlatformRole::ProductManager);

        expect(fn () => app(AssignProductToSupplier::class)->handle($manager, $this->product, null, $this->supplier, supplierAssignmentPrice('1400.00')))
            ->toThrow(AuthorizationException::class);

        $draft = Supplier::factory()->draft()->create();

        expect(fn () => app(AssignProductToSupplier::class)->handle($this->staff, $this->product, null, $draft, supplierAssignmentPrice('1400.00')))
            ->toThrow(InvalidArgumentException::class, 'approved Supplier');
    });
});

describe('changing the Supplier Base Price', function () {
    it('records a new price version and audit entry, and never touches the platform rate', function () {
        $offer = app(AssignProductToSupplier::class)->handle($this->staff, $this->product, null, $this->supplier, supplierAssignmentPrice('1400.00'));

        app(ChangeSupplierBasePrice::class)->handle($this->staff, $offer, supplierAssignmentPrice('1380.00'), 'Better terms agreed.');

        $offer->refresh();

        expect($offer->supplier_rate->toDecimal())->toBe('1380.00')
            ->and($offer->platform_rate->toDecimal())->toBe('2000.00')
            ->and($offer->priceHistory)->toHaveCount(2)
            ->and(AuditLog::query()->where('action', 'supplier_offer.rates_changed')->count())->toBe(1);
    });

    it('needs a reason and a positive price', function () {
        $offer = app(AssignProductToSupplier::class)->handle($this->staff, $this->product, null, $this->supplier, supplierAssignmentPrice('1400.00'));

        expect(fn () => app(ChangeSupplierBasePrice::class)->handle($this->staff, $offer, supplierAssignmentPrice('1300.00'), '  '))
            ->toThrow(InvalidArgumentException::class, 'reason')
            ->and(fn () => app(ChangeSupplierBasePrice::class)->handle($this->staff, $offer, supplierAssignmentPrice('0.00'), 'Zero'))
            ->toThrow(InvalidArgumentException::class, 'greater than zero');
    });
});

describe('listing approval', function () {
    function supplierAssignmentApprove(Supplier $supplier, Product $product, $reviewer, ?string $override = null): SupplierOffer
    {
        $listing = $supplier->listings()->create([
            'product_name' => 'Cotton pants',
            'category_suggestion' => 'Menswear',
            'status' => ListingStatus::UnderReview,
            'submitted_at' => now(),
        ]);

        $item = $listing->items()->create([
            'supplier_sku' => 'SUP-'.Str::upper(Str::random(6)),
            'supplier_rate' => Money::fromDecimal('1500.00', Currency::BDT),
            'currency_code' => 'BDT',
            'available_quantity' => 10,
            'minimum_supply_quantity' => 1,
        ]);

        app(DecideSupplierListing::class)->handle(
            $listing,
            $reviewer,
            ['connect_product_id' => $product->public_id],
            [[
                'item_id' => $item->public_id,
                'decision' => 'approve',
                'platform_rate' => Money::fromDecimal('2000.00', Currency::BDT),
                ...($override === null ? [] : ['supplier_rate' => Money::fromDecimal($override, Currency::BDT)]),
            ]],
            'Looks right.',
        );

        return $item->refresh()->offer;
    }

    it('uses the Supplier\'s proposed price as the base price, marked as a listing', function () {
        $offer = supplierAssignmentApprove($this->supplier, $this->product, $this->staff);

        expect($offer->origin)->toBe(OfferOrigin::SupplierListing)
            ->and($offer->assigned_by)->toBeNull()
            ->and($offer->supplier_rate->toDecimal())->toBe('1500.00')
            ->and($offer->platform_rate->toDecimal())->toBe('2000.00');
    });

    it('lets staff confirm a different base price, recorded against the proposal', function () {
        $offer = supplierAssignmentApprove($this->supplier, $this->product, $this->staff, '1420.00');

        expect($offer->supplier_rate->toDecimal())->toBe('1420.00')
            ->and($offer->priceHistory->first()->reason)->toContain('1420.00')->toContain('1500.00');
    });

    it('maintains the one offer when a Supplier\'s listing is approved for a Product already assigned to it', function () {
        $assigned = app(AssignProductToSupplier::class)->handle($this->staff, $this->product, null, $this->supplier, supplierAssignmentPrice('1450.00'));

        $offer = supplierAssignmentApprove($this->supplier, $this->product, $this->staff, '1380.00');

        expect($offer->id)->toBe($assigned->id)
            ->and(SupplierOffer::query()->where('supplier_id', $this->supplier->id)->count())->toBe(1)
            ->and($offer->refresh()->supplier_rate->toDecimal())->toBe('1380.00')
            ->and($offer->origin)->toBe(OfferOrigin::AdminAssignment)
            ->and($offer->priceHistory)->toHaveCount(2);
    });

    it('lands in the same table as a manual assignment, so a Supplier sees both together', function () {
        supplierAssignmentApprove($this->supplier, $this->product, $this->staff);

        $other = supplierAssignmentProduct('SA-SHIRT');
        app(AssignProductToSupplier::class)->handle($this->staff, $other, null, $this->supplier, supplierAssignmentPrice('900.00'));

        $origins = $this->supplier->offers()->get()->pluck('origin.value')->sort()->values()->all();

        expect($origins)->toBe(['admin_assignment', 'supplier_listing']);
    });
});
