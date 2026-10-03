<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Data\AllocationCandidate;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Exceptions\AllocationRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Order\Queries\AllocationSourceCandidates;
use App\Domain\Sourcing\Actions\ManageSourcingGroups;
use App\Domain\Sourcing\Exceptions\SourcingGroupRefused;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The allocation panel resolves candidates from the order line's frozen
 * sourcing group and canonical variation: Supplier offers and warehouse stock
 * from every compatible member product, nothing unrelated, never the wrong
 * variation, and never an automatic choice.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->manage = app(ManageSourcingGroups::class);

    $this->group = $this->manage->create($this->staff, ['code' => 'regular-pants', 'name_en' => "Men's regular pants", 'name_bn' => 'রেগুলার প্যান্ট']);

    $this->canonical = groupAllocationProduct("Men's Regular Pants", 'RP');
    $this->manage->addProduct($this->staff, $this->group, $this->canonical, 'Reference.');
    $this->canonicalM = groupAllocationVariant($this->canonical, 'RP-M');
    $this->canonicalL = groupAllocationVariant($this->canonical, 'RP-L');
});

function groupAllocationProduct(string $name, string $sku): Product
{
    $product = websiteTestProduct(['name' => $name, 'sku' => $sku.'-'.Str::upper(Str::random(4))]);
    $product->forceFill(['base_cost' => Money::fromDecimal('700.00', Currency::BDT)])->save();

    return $product;
}

function groupAllocationVariant(Product $product, string $sku): ProductVariant
{
    return ProductVariant::create(['product_id' => $product->id, 'sku' => $sku, 'combination_key' => $sku]);
}

/** A member product with one variation mapped to a canonical variation. */
function groupAllocationMember(string $name, string $sku, ProductVariant $canonicalVariant): array
{
    $product = groupAllocationProduct($name, $sku);
    test()->manage->addProduct(test()->staff, test()->group, $product, 'Equivalent.');
    $variant = groupAllocationVariant($product, $sku.'-V');
    test()->manage->mapVariant(test()->staff, test()->group, $product, $variant, $canonicalVariant, 'Same fit and size.');

    return [$product, $variant];
}

function groupAllocationOffer(Product $product, ?ProductVariant $variant, string $rate, int $stock = 10, ?Supplier $supplier = null): SupplierOffer
{
    // The variation is fixed when an offer is written, so it is created here
    // rather than through supplierTestOffer().
    $offer = SupplierOffer::create([
        'supplier_id' => ($supplier ?? Supplier::factory()->create(['status' => SupplierStatus::Approved]))->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant?->id,
        'status' => OfferStatus::Active,
        'supplier_rate' => Money::fromDecimal($rate, Currency::BDT),
        'platform_rate' => Money::fromDecimal('1300.00', Currency::BDT),
        'currency_code' => 'BDT',
        'wholesale_enabled' => true,
        'activated_at' => now(),
    ]);
    $offer->stock()->create(['quantity' => $stock]);
    supplierTestOfferPriceVersion($offer);

    return $offer->refresh();
}

function groupAllocationWarehouse(Product $product, ?ProductVariant $variant, int $available = 10, bool $active = true, string $name = 'Dhaka Central'): StockItem
{
    $warehouse = Warehouse::create(['name' => $name, 'code' => 'W-'.random_int(1000, 9999), 'is_active' => $active]);

    return StockItem::create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant?->id,
        'available' => $available,
    ]);
}

function groupAllocationLine(ProductVariant $variant, int $quantity = 2): OrderItem
{
    $product = $variant->product;
    $order = Order::factory()->create();

    return OrderItem::create([
        'order_id' => $order->id,
        'line_number' => 1,
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'sku' => $variant->sku,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('1300.00', Currency::BDT)->multipliedBy($quantity),
        'line_total' => Money::fromDecimal('1300.00', Currency::BDT)->multipliedBy($quantity),
        'created_at' => now(),
    ])->refresh();
}

/** @return list<AllocationCandidate> */
function groupAllocationCandidates(OrderItem $line): array
{
    return app(AllocationSourceCandidates::class)->forLine($line);
}

describe('which sources appear', function () {
    it('shows five suppliers on five member products together with the warehouse, all from one group', function () {
        $line = groupAllocationLine($this->canonicalM);

        $rates = ['900.00', '850.00', '1000.00', '780.00', '950.00'];

        foreach ($rates as $index => $rate) {
            [$product, $variant] = groupAllocationMember("Member {$index}", "M{$index}", $this->canonicalM);
            groupAllocationOffer($product, $variant, $rate);
        }

        groupAllocationWarehouse($this->canonical, $this->canonicalM);

        $candidates = groupAllocationCandidates($line);
        $suppliers = array_filter($candidates, fn (AllocationCandidate $c) => $c->sourceType === AllocationSourceType::SupplierOffer);
        $warehouses = array_filter($candidates, fn (AllocationCandidate $c) => $c->sourceType === AllocationSourceType::Warehouse);

        expect($suppliers)->toHaveCount(5)
            ->and($warehouses)->toHaveCount(1)
            ->and(collect($suppliers)->pluck('matchKind')->unique()->all())->toBe(['group'])
            ->and(collect($suppliers)->pluck('unitCost')->map->toDecimal()->sort()->values()->all())
            ->toBe(['780.00', '850.00', '900.00', '950.00', '1000.00'])
            ->and(collect($suppliers)->pluck('supplierId')->unique())->toHaveCount(5);
    });

    it('shows a warehouse stocked on a member product alongside the canonical supplier offer', function () {
        $line = groupAllocationLine($this->canonicalM);
        [$member, $memberVariant] = groupAllocationMember('Cotton Pants', 'CP', $this->canonicalM);
        $memberStock = groupAllocationWarehouse($member, $memberVariant, 6, name: 'Chattogram Hub');
        groupAllocationOffer($this->canonical, $this->canonicalM, '900.00');

        $candidates = groupAllocationCandidates($line);

        expect($candidates)->toHaveCount(2);

        $warehouse = collect($candidates)->firstWhere('sourceType', AllocationSourceType::Warehouse);

        // Reached through the group, so identified by its own stock item.
        expect($warehouse->sourceId)->toBe($memberStock->public_id)
            ->and($warehouse->sourceLabel)->toBe('Chattogram Hub')
            ->and($warehouse->matchKind)->toBe('group')
            ->and($warehouse->availableToPromise)->toBe(6);
    });

    it('never shows unrelated products or the wrong variation', function () {
        $line = groupAllocationLine($this->canonicalM);
        [$member, $memberM] = groupAllocationMember('Cotton Pants', 'CP', $this->canonicalM);
        $memberL = groupAllocationVariant($member, 'CP-L');
        $this->manage->mapVariant($this->staff, $this->group, $member, $memberL, $this->canonicalL, 'Size L.');

        $unrelated = groupAllocationProduct('Leather Jacket', 'LJ');
        $unmappedVariant = groupAllocationVariant($member, 'CP-XL');

        $wanted = groupAllocationOffer($member, $memberM, '900.00');
        groupAllocationOffer($member, $memberL, '800.00');          // maps to canonical L, not M
        groupAllocationOffer($member, $unmappedVariant, '700.00');   // member variation with no mapping
        groupAllocationOffer($unrelated, null, '500.00');            // not in any group
        groupAllocationWarehouse($unrelated, null);
        groupAllocationWarehouse($member, $memberL);                 // wrong size

        $ids = collect(groupAllocationCandidates($line))->pluck('sourceId')->all();

        expect($ids)->toBe([$wanted->public_id]);
    });

    it('leaves out inactive warehouses, suspended offers, non-operational suppliers and removed mappings', function () {
        $line = groupAllocationLine($this->canonicalM);
        [$member, $memberM] = groupAllocationMember('Cotton Pants', 'CP', $this->canonicalM);

        $live = groupAllocationOffer($member, $memberM, '900.00');
        $suspended = groupAllocationOffer($member, $memberM, '800.00');
        $suspended->forceFill(['status' => OfferStatus::Suspended])->save();
        groupAllocationOffer($member, $memberM, '700.00', supplier: Supplier::factory()->kycPending()->create());
        groupAllocationWarehouse($member, $memberM, 10, active: false);

        expect(collect(groupAllocationCandidates($line))->pluck('sourceId')->all())->toBe([$live->public_id]);

        $this->manage->unmapVariant($this->staff, $this->group->variantMappings()->active()->where('product_id', $member->id)->firstOrFail(), 'No longer equivalent.');

        expect(groupAllocationCandidates($line))->toBe([]);
    });

    it('reports an insufficient-stock source as ineligible with the reason rather than hiding it', function () {
        $line = groupAllocationLine($this->canonicalM, quantity: 5);
        [$member, $memberM] = groupAllocationMember('Cotton Pants', 'CP', $this->canonicalM);
        groupAllocationOffer($member, $memberM, '900.00', stock: 2);

        $candidate = groupAllocationCandidates($line)[0];

        expect($candidate->isEligible)->toBeFalse()
            ->and($candidate->ineligibleReason)->toContain('2 of the 5');
    });

    it('keeps an unmatched line on the existing exact-match and linked behaviour', function () {
        $loose = groupAllocationProduct('Loose Product', 'LP');
        $order = Order::factory()->create();
        $line = OrderItem::create([
            'order_id' => $order->id, 'line_number' => 1, 'product_id' => $loose->id, 'sku' => $loose->sku,
            'product_name' => $loose->name, 'quantity' => 1, 'currency_code' => 'BDT',
            'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
            'line_subtotal' => Money::fromDecimal('1300.00', Currency::BDT),
            'line_total' => Money::fromDecimal('1300.00', Currency::BDT), 'created_at' => now(),
        ])->refresh();
        groupAllocationOffer($loose, null, '900.00');

        expect($line->sourcing_group_id)->toBeNull()
            ->and(app(AllocationSourceCandidates::class)->isGrouped($line))->toBeFalse()
            ->and(groupAllocationCandidates($line))->toHaveCount(1);
    });
});

describe('allocating', function () {
    it('allocates a group supplier explicitly, snapshotting its rate and raising a pending payable', function () {
        $line = groupAllocationLine($this->canonicalM);
        [$member, $memberM] = groupAllocationMember('Cotton Pants', 'CP', $this->canonicalM);
        $offer = groupAllocationOffer($member, $memberM, '900.00');

        $allocation = app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::SupplierOffer, $offer->public_id, $this->staff, 'Cheapest compatible, confirmed by staff.');

        $payable = SupplierPayable::query()->sole();

        expect($allocation->supplier_offer_id)->toBe($offer->id)
            ->and($allocation->unit_cost->toDecimal())->toBe('900.00')
            ->and($allocation->expected_margin->toDecimal())->toBe('800.00')
            ->and($payable->status)->toBe(PayableStatus::Pending)
            ->and($payable->gross_amount->toDecimal())->toBe('1800.00')
            ->and($payable->settled_at)->toBeNull();
    });

    it('allocates group warehouse stock through the reservation flow and owes no supplier', function () {
        $line = groupAllocationLine($this->canonicalM);
        [$member, $memberM] = groupAllocationMember('Cotton Pants', 'CP', $this->canonicalM);
        $stock = groupAllocationWarehouse($member, $memberM, 10);

        $allocation = app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::Warehouse, $stock->public_id, $this->staff, 'Group warehouse stock chosen by staff.');

        expect($allocation->linked_stock_item_id)->toBe($stock->id)
            ->and($allocation->reservation)->not->toBeNull()
            ->and(SupplierPayable::query()->count())->toBe(0)
            ->and($stock->refresh()->available)->toBe(8)
            ->and($stock->reserved)->toBe(2);
    });

    it('never picks a source on its own: nothing is allocated until staff choose', function () {
        $line = groupAllocationLine($this->canonicalM);
        [$member, $memberM] = groupAllocationMember('Cotton Pants', 'CP', $this->canonicalM);
        groupAllocationOffer($member, $memberM, '900.00');

        groupAllocationCandidates($line);

        expect(OrderItemAllocation::query()->count())->toBe(0)
            ->and(SupplierPayable::query()->count())->toBe(0);
    });

    it('refuses a source outside the line\'s group even if asked for directly', function () {
        $line = groupAllocationLine($this->canonicalM);
        $unrelated = groupAllocationOffer(groupAllocationProduct('Leather Jacket', 'LJ'), null, '500.00');

        expect(fn () => app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::SupplierOffer, $unrelated->public_id, $this->staff, 'Trying an unrelated source.'))
            ->toThrow(AllocationRefused::class, 'does not serve this product');
    });

    it('is idempotent when the same group source is confirmed twice', function () {
        $line = groupAllocationLine($this->canonicalM);
        [$member, $memberM] = groupAllocationMember('Cotton Pants', 'CP', $this->canonicalM);
        $offer = groupAllocationOffer($member, $memberM, '900.00');
        $allocate = fn () => app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::SupplierOffer, $offer->public_id, $this->staff, 'Confirmed by staff.');

        $first = $allocate();
        $second = $allocate();

        expect($second->id)->toBe($first->id)
            ->and(OrderItemAllocation::query()->count())->toBe(1)
            ->and(SupplierPayable::query()->count())->toBe(1)
            ->and($offer->stock->refresh()->reserved_quantity)->toBe(2);
    });

    it('stops a mapping or product being removed while an allocation relies on it', function () {
        $line = groupAllocationLine($this->canonicalM);
        [$member, $memberM] = groupAllocationMember('Cotton Pants', 'CP', $this->canonicalM);
        $offer = groupAllocationOffer($member, $memberM, '900.00');
        app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::SupplierOffer, $offer->public_id, $this->staff, 'Confirmed by staff.');

        $mapping = $this->group->variantMappings()->active()->where('product_id', $member->id)->firstOrFail();

        expect(fn () => $this->manage->unmapVariant($this->staff, $mapping, 'Cleaning up.'))
            ->toThrow(SourcingGroupRefused::class, 'open order allocation')
            ->and(fn () => $this->manage->removeProduct($this->staff, $this->group, $member, 'Cleaning up.'))
            ->toThrow(SourcingGroupRefused::class, 'open order allocation');

        // The allocation and its snapshot are untouched.
        expect($mapping->fresh()->status->value)->toBe('active')
            ->and(OrderItemAllocation::query()->sole()->unit_cost->toDecimal())->toBe('900.00');
    });
});

describe('the candidates endpoint', function () {
    beforeEach(function () {
        $this->line = groupAllocationLine($this->canonicalM);
        [$this->member, $this->memberM] = groupAllocationMember('Cotton Pants', 'CP', $this->canonicalM);

        $this->cheap = groupAllocationOffer($this->member, $this->memberM, '780.00', supplier: Supplier::factory()->create(['status' => SupplierStatus::Approved, 'business_name' => 'Alpha Textiles']));
        $this->dear = groupAllocationOffer($this->canonical, $this->canonicalM, '950.00', supplier: Supplier::factory()->create(['status' => SupplierStatus::Approved, 'business_name' => 'Beta Garments']));
        $this->short = groupAllocationOffer($this->member, $this->memberM, '850.00', stock: 1, supplier: Supplier::factory()->create(['status' => SupplierStatus::Approved, 'business_name' => 'Gamma Mills']));
        $this->stock = groupAllocationWarehouse($this->canonical, $this->canonicalM, 10);
    });

    function groupAllocationSources(array $query = []): array
    {
        $response = test()->actingAs(test()->staff)->getJson(
            route('admin.orders.lines.sources', ['order' => test()->line->order->public_id, 'item' => test()->line->public_id, ...$query]),
        )->assertOk();

        return $response->json();
    }

    it('returns the group-scoped candidates with the line\'s frozen requirement', function () {
        $body = groupAllocationSources();

        expect($body['candidates'])->toHaveCount(4)
            ->and($body['sourcing']['state'])->toBe('matched')
            ->and($body['sourcing']['group']['code'])->toBe('regular-pants')
            ->and($body['sourcing']['canonical_product']['name'])->toBe("Men's Regular Pants")
            ->and($body['sourcing']['canonical_variant'])->toContain('RP-M');
    });

    it('filters by Supplier or Warehouse, availability and search, and sorts by cost on request only', function () {
        expect(collect(groupAllocationSources(['source_type' => 'warehouse'])['candidates'])->pluck('source_type')->unique()->all())->toBe(['warehouse'])
            ->and(groupAllocationSources(['source_type' => 'supplier_offer'])['candidates'])->toHaveCount(3)
            ->and(groupAllocationSources(['availability' => 'available'])['candidates'])->toHaveCount(3)
            ->and(collect(groupAllocationSources(['search' => 'beta'])['candidates'])->pluck('supplier_name')->all())->toBe(['Beta Garments']);

        $asc = collect(groupAllocationSources(['source_type' => 'supplier_offer', 'sort' => 'cost_asc'])['candidates'])->pluck('unit_cost.amount')->all();
        $desc = collect(groupAllocationSources(['source_type' => 'supplier_offer', 'sort' => 'cost_desc'])['candidates'])->pluck('unit_cost.amount')->all();

        expect($asc)->toBe(['780.00', '850.00', '950.00'])->and($desc)->toBe(['950.00', '850.00', '780.00']);

        // Without a sort the platform imposes no ranking at all.
        expect(OrderItemAllocation::query()->count())->toBe(0);
    });

    it('flags an unmatched line and keeps the catalogue-wide search for it only', function () {
        $loose = groupAllocationProduct('Loose Product', 'LP');
        $order = Order::factory()->create();
        $unmatched = OrderItem::create([
            'order_id' => $order->id, 'line_number' => 1, 'product_id' => $loose->id, 'sku' => $loose->sku, 'product_name' => $loose->name,
            'quantity' => 1, 'currency_code' => 'BDT', 'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
            'line_subtotal' => Money::fromDecimal('1300.00', Currency::BDT), 'line_total' => Money::fromDecimal('1300.00', Currency::BDT),
            'created_at' => now(),
        ]);

        $body = $this->actingAs($this->staff)->getJson(route('admin.orders.lines.sources', ['order' => $order->public_id, 'item' => $unmatched->public_id]))->assertOk()->json();

        expect($body['sourcing']['state'])->toBe('unmatched')->and($body['sourcing']['group'])->toBeNull();

        // A grouped line's catalogue search never leaves its group.
        $search = $this->actingAs($this->staff)->getJson(route('admin.orders.lines.sources.search', [
            'order' => $this->line->order->public_id, 'item' => $this->line->public_id, 'query' => 'leather',
        ]))->assertOk()->json();

        expect($search['candidates'])->toBe([]);
    });

    it('is refused to staff without allocation-source access and to partner sessions', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::ReportViewer))
            ->getJson(route('admin.orders.lines.sources', ['order' => $this->line->order->public_id, 'item' => $this->line->public_id]))
            ->assertForbidden();

        $this->actingAs(testBusinessAccount()->owner)
            ->getJson(route('admin.orders.lines.sources', ['order' => $this->line->order->public_id, 'item' => $this->line->public_id]))
            ->assertForbidden();
    });

    it('never puts a supplier identity or rate into a partner-facing payload', function () {
        $page = $this->actingAs($this->staff)->get(route('admin.orders.show', $this->line->order->public_id));

        // The Admin order page is staff-only; the candidates travel by JSON.
        $page->assertOk()->assertInertia(fn (Assert $inertia) => $inertia->component('admin/orders/show'));
    });
});
