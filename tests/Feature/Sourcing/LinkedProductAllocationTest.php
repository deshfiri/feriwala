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
use App\Domain\Sourcing\Actions\ManageProductLinks;
use App\Domain\Sourcing\Models\ProductLink;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/*
 * The allocation panel resolves candidates from the ordered Product and every
 * Product linked to it as the same Product -- directly or through other links
 * -- on the variations staff matched: Supplier offers and warehouse stock from
 * all of them, listed once each, never the wrong variation, and never an
 * automatic choice.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->links = app(ManageProductLinks::class);

    $this->ordered = linkedAllocationProduct("Men's Regular Pants", 'RP');
    $this->orderedM = linkedAllocationVariant($this->ordered, 'RP-M');
    $this->orderedL = linkedAllocationVariant($this->ordered, 'RP-L');
});

function linkedAllocationProduct(string $name, string $sku): Product
{
    $product = websiteTestProduct(['name' => $name, 'sku' => strtoupper(Str::random(9))]);
    $product->forceFill(['base_cost' => Money::fromDecimal('700.00', Currency::BDT)])->save();

    return $product;
}

function linkedAllocationVariant(Product $product, string $sku): ProductVariant
{
    return ProductVariant::create(['product_id' => $product->id, 'sku' => $sku.'-'.Str::upper(Str::random(4)), 'combination_key' => $sku.Str::random(4)]);
}

/**
 * Link two Products as the same Product, matching one variation of each when
 * both are given.
 */
function linkedAllocationLink(Product $one, Product $two, ?ProductVariant $oneVariant = null, ?ProductVariant $twoVariant = null): ProductLink
{
    $actions = app(ManageProductLinks::class);
    $link = ProductLink::query()->active()
        ->where('product_a_id', min($one->id, $two->id))->where('product_b_id', max($one->id, $two->id))->first()
        ?? $actions->link(test()->staff, $one, $two);

    if ($oneVariant !== null || $twoVariant !== null) {
        $oneIsA = $link->product_a_id === $one->id;
        $actions->mapVariants(test()->staff, $link, $oneIsA ? $oneVariant : $twoVariant, $oneIsA ? $twoVariant : $oneVariant);
    }

    return $link;
}

/** A Product linked to the ordered one, with one variation matched to its M. @return array{0: Product, 1: ProductVariant} */
function linkedAllocationMember(string $name, string $sku): array
{
    $product = linkedAllocationProduct($name, $sku);
    $variant = linkedAllocationVariant($product, $sku.'-V');
    linkedAllocationLink(test()->ordered, $product, test()->orderedM, $variant);

    return [$product, $variant];
}

function linkedAllocationOffer(Product $product, ?ProductVariant $variant, string $rate, int $stock = 10, ?Supplier $supplier = null): SupplierOffer
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

function linkedAllocationWarehouse(Product $product, ?ProductVariant $variant, int $available = 10, bool $active = true, string $name = 'Dhaka Central'): StockItem
{
    $warehouse = Warehouse::create(['name' => $name, 'code' => 'W-'.random_int(1000, 9999), 'is_active' => $active]);

    return StockItem::create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant?->id,
        'available' => $available,
    ]);
}

function linkedAllocationLine(Product $product, ?ProductVariant $variant, int $quantity = 2): OrderItem
{
    $order = Order::factory()->create();

    return OrderItem::create([
        'order_id' => $order->id,
        'line_number' => 1,
        'product_id' => $product->id,
        'product_variant_id' => $variant?->id,
        'sku' => $variant?->sku ?? $product->sku,
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
function linkedAllocationCandidates(OrderItem $line): array
{
    return app(AllocationSourceCandidates::class)->forLine($line);
}

describe('which sources appear', function () {
    it('shows five suppliers on five linked Products together with the warehouse', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM);

        foreach (['900.00', '850.00', '1000.00', '780.00', '950.00'] as $index => $rate) {
            [$product, $variant] = linkedAllocationMember("Member {$index}", "M{$index}");
            linkedAllocationOffer($product, $variant, $rate);
        }

        linkedAllocationWarehouse($this->ordered, $this->orderedM);

        $candidates = linkedAllocationCandidates($line);
        $suppliers = array_filter($candidates, fn (AllocationCandidate $c) => $c->sourceType === AllocationSourceType::SupplierOffer);
        $warehouses = array_filter($candidates, fn (AllocationCandidate $c) => $c->sourceType === AllocationSourceType::Warehouse);

        expect($suppliers)->toHaveCount(5)
            ->and($warehouses)->toHaveCount(1)
            ->and(collect($suppliers)->pluck('matchKind')->unique()->all())->toBe(['linked_product'])
            ->and(collect($suppliers)->pluck('unitCost')->map->toDecimal()->sort()->values()->all())
            ->toBe(['780.00', '850.00', '900.00', '950.00', '1000.00'])
            ->and(collect($suppliers)->pluck('supplierId')->unique())->toHaveCount(5);
    });

    it('carries the connected Product title, BPC, variation, rate, payable, lead time and mode', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM, quantity: 3);
        [$member, $memberVariant] = linkedAllocationMember('Cotton Pants', 'CP');
        $offer = linkedAllocationOffer($member, $memberVariant, '900.00');
        $offer->forceFill(['lead_time_days' => 4])->save();

        $candidate = linkedAllocationCandidates($line)[0];

        expect($candidate->sourceProductName)->toBe('Cotton Pants')
            ->and($candidate->sourceProductBpc)->toBe($member->sku)
            ->and($candidate->sourceVariantLabel)->toBe($memberVariant->label())
            ->and($candidate->unitCost->toDecimal())->toBe('900.00')
            ->and($candidate->expectedPayable?->toDecimal())->toBe('2700.00')
            ->and($candidate->leadTimeDays)->toBe(4)
            ->and($candidate->availableToPromise)->toBe(10)
            ->and($candidate->supplyMode->value)->toBe('ready_stock')
            ->and($candidate->toArray())->toHaveKeys(['source_product_bpc', 'source_variant_label', 'expected_payable', 'supply_mode', 'fulfilment_capacity', 'lead_time_days', 'is_preferred']);
    });

    it('shows a warehouse stocked on a linked Product alongside the ordered Product\'s offer', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM);
        [$member, $memberVariant] = linkedAllocationMember('Cotton Pants', 'CP');
        $memberStock = linkedAllocationWarehouse($member, $memberVariant, 6, name: 'Chattogram Hub');
        linkedAllocationOffer($this->ordered, $this->orderedM, '900.00');

        $candidates = linkedAllocationCandidates($line);
        $warehouse = collect($candidates)->firstWhere('sourceType', AllocationSourceType::Warehouse);

        // Reached through a link, so identified by its own stock item.
        expect($candidates)->toHaveCount(2)
            ->and($warehouse->sourceId)->toBe($memberStock->public_id)
            ->and($warehouse->sourceLabel)->toBe('Chattogram Hub')
            ->and($warehouse->matchKind)->toBe('linked_product')
            ->and($warehouse->availableToPromise)->toBe(6);
    });

    it('reaches Products through indirect links, and stops at a variation nobody matched', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM);
        [$b, $bVariant] = linkedAllocationMember('Product B', 'B');
        $c = linkedAllocationProduct('Product C', 'C');
        $cVariant = linkedAllocationVariant($c, 'C-V');
        $offerOnC = linkedAllocationOffer($c, $cVariant, '800.00');

        // B-C is linked but its variations are not matched: C is not offered.
        linkedAllocationLink($b, $c);
        expect(linkedAllocationCandidates($line))->toBe([]);

        // Staff match B's variation to C's, and C is reached through B.
        $link = ProductLink::query()->active()->where('product_a_id', min($b->id, $c->id))->where('product_b_id', max($b->id, $c->id))->firstOrFail();
        $bIsA = $link->product_a_id === $b->id;
        $this->links->mapVariants($this->staff, $link, $bIsA ? $bVariant : $cVariant, $bIsA ? $cVariant : $bVariant);

        expect(collect(linkedAllocationCandidates($line))->pluck('sourceId')->all())->toBe([$offerOnC->public_id]);
    });

    it('lists a source once however many paths reach it, even around a circle', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM);
        [$b, $bVariant] = linkedAllocationMember('Product B', 'B');
        [$c, $cVariant] = linkedAllocationMember('Product C', 'C');

        // B-C as well as A-B and A-C: C is reachable directly and via B, and
        // the three Products form a circle.
        linkedAllocationLink($b, $c, $bVariant, $cVariant);

        $offer = linkedAllocationOffer($c, $cVariant, '800.00');
        $stock = linkedAllocationWarehouse($b, $bVariant, 5);

        $ids = collect(linkedAllocationCandidates($line))->map(fn (AllocationCandidate $candidate) => $candidate->sourceType->value.':'.$candidate->sourceId)->all();

        expect($ids)->toHaveCount(2)
            ->and($ids)->toEqualCanonicalizing(['supplier_offer:'.$offer->public_id, 'warehouse:'.$stock->public_id]);
    });

    it('never shows unrelated Products, an unmatched variation or the wrong one', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM);
        [$member, $memberM] = linkedAllocationMember('Cotton Pants', 'CP');
        $memberL = linkedAllocationVariant($member, 'CP-L');
        $unmatched = linkedAllocationVariant($member, 'CP-XL');

        // L is matched to the ordered product's L, not to M.
        linkedAllocationLink($this->ordered, $member, $this->orderedL, $memberL);

        $unrelated = linkedAllocationProduct('Leather Jacket', 'LJ');

        $wanted = linkedAllocationOffer($member, $memberM, '900.00');
        linkedAllocationOffer($member, $memberL, '800.00');        // matches L, not M
        linkedAllocationOffer($member, $unmatched, '700.00');      // no match at all
        linkedAllocationOffer($unrelated, null, '500.00');         // not linked
        linkedAllocationWarehouse($unrelated, null);
        linkedAllocationWarehouse($member, $memberL);              // wrong size

        expect(collect(linkedAllocationCandidates($line))->pluck('sourceId')->all())->toBe([$wanted->public_id]);
    });

    it('leaves out inactive warehouses, suspended offers and non-operational suppliers, and drops a source when staff unlink', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM);
        [$member, $memberM] = linkedAllocationMember('Cotton Pants', 'CP');

        $live = linkedAllocationOffer($member, $memberM, '900.00');
        $suspended = linkedAllocationOffer($member, $memberM, '800.00');
        $suspended->forceFill(['status' => OfferStatus::Suspended])->save();
        linkedAllocationOffer($member, $memberM, '700.00', supplier: Supplier::factory()->kycPending()->create());
        linkedAllocationWarehouse($member, $memberM, 10, active: false);

        expect(collect(linkedAllocationCandidates($line))->pluck('sourceId')->all())->toBe([$live->public_id]);

        $this->links->unlink($this->staff, ProductLink::query()->active()->firstOrFail(), 'No longer the same.');

        expect(linkedAllocationCandidates($line))->toBe([]);
    });

    it('reports an insufficient-stock source as ineligible with the reason rather than hiding it', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM, quantity: 5);
        [$member, $memberM] = linkedAllocationMember('Cotton Pants', 'CP');
        linkedAllocationOffer($member, $memberM, '900.00', stock: 2);

        $candidate = linkedAllocationCandidates($line)[0];

        expect($candidate->isEligible)->toBeFalse()
            ->and($candidate->ineligibleReason)->toContain('2 of the 5');
    });

    it('passes Products without variations straight through, and keeps a unique Product on its own sources', function () {
        $plainOrdered = linkedAllocationProduct('Plain A', 'PA');
        $plainLinked = linkedAllocationProduct('Plain B', 'PB');
        $alone = linkedAllocationProduct('Plain Alone', 'PC');
        linkedAllocationLink($plainOrdered, $plainLinked);

        $viaLink = linkedAllocationOffer($plainLinked, null, '900.00');
        $own = linkedAllocationOffer($alone, null, '850.00');
        linkedAllocationOffer($plainLinked, null, '600.00', supplier: Supplier::factory()->kycPending()->create());

        $linkedLine = linkedAllocationLine($plainOrdered, null);
        $aloneLine = linkedAllocationLine($alone, null);

        expect(collect(linkedAllocationCandidates($linkedLine))->pluck('sourceId')->all())->toBe([$viaLink->public_id])
            ->and(collect(linkedAllocationCandidates($aloneLine))->pluck('sourceId')->all())->toBe([$own->public_id])
            ->and(app(AllocationSourceCandidates::class)->linkedProductCount($aloneLine))->toBe(0)
            ->and(app(AllocationSourceCandidates::class)->linkedProductCount($linkedLine))->toBe(1);
    });
});

describe('allocating', function () {
    it('allocates a linked Product\'s supplier explicitly, snapshotting its rate and the Product it came from, and raising a pending payable', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM);
        [$member, $memberM] = linkedAllocationMember('Cotton Pants', 'CP');
        $offer = linkedAllocationOffer($member, $memberM, '900.00');

        $allocation = app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::SupplierOffer, $offer->public_id, $this->staff, 'Confirmed by staff.');

        $payable = SupplierPayable::query()->sole();

        expect($allocation->supplier_offer_id)->toBe($offer->id)
            ->and($allocation->unit_cost->toDecimal())->toBe('900.00')
            ->and($allocation->expected_margin->toDecimal())->toBe('800.00')
            ->and($allocation->source_product_id)->toBe($member->id)
            ->and($allocation->source_product_variant_id)->toBe($memberM->id)
            ->and($allocation->source_match_kind)->toBe('linked_product')
            ->and($payable->status)->toBe(PayableStatus::Pending)
            ->and($payable->gross_amount->toDecimal())->toBe('1800.00')
            ->and($payable->settled_at)->toBeNull();
    });

    it('snapshots an exact-Product allocation against the ordered Product itself', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM);
        $offer = linkedAllocationOffer($this->ordered, $this->orderedM, '950.00');

        $allocation = app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::SupplierOffer, $offer->public_id, $this->staff, 'Own offer, confirmed.');

        expect($allocation->source_product_id)->toBe($this->ordered->id)
            ->and($allocation->source_product_variant_id)->toBe($this->orderedM->id)
            ->and($allocation->source_match_kind)->toBe('exact');
    });

    it('allocates a linked warehouse through the reservation flow and owes no supplier', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM);
        [$member, $memberM] = linkedAllocationMember('Cotton Pants', 'CP');
        $stock = linkedAllocationWarehouse($member, $memberM, 10);

        $allocation = app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::Warehouse, $stock->public_id, $this->staff, 'Linked warehouse stock chosen by staff.');

        expect($allocation->linked_stock_item_id)->toBe($stock->id)
            ->and($allocation->reservation)->not->toBeNull()
            ->and($allocation->source_product_id)->toBe($member->id)
            ->and(SupplierPayable::query()->count())->toBe(0)
            ->and($stock->refresh()->available)->toBe(8)
            ->and($stock->reserved)->toBe(2);
    });

    it('never picks a source on its own: nothing is allocated until staff choose', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM);
        [$member, $memberM] = linkedAllocationMember('Cotton Pants', 'CP');
        linkedAllocationOffer($member, $memberM, '900.00');

        linkedAllocationCandidates($line);

        expect(OrderItemAllocation::query()->count())->toBe(0)
            ->and(SupplierPayable::query()->count())->toBe(0);
    });

    it('refuses a source outside the network even if asked for directly', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM);
        $unrelated = linkedAllocationOffer(linkedAllocationProduct('Leather Jacket', 'LJ'), null, '500.00');

        expect(fn () => app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::SupplierOffer, $unrelated->public_id, $this->staff, 'Trying an unrelated source.'))
            ->toThrow(AllocationRefused::class, 'does not serve this product');
    });

    it('is idempotent when the same linked source is confirmed twice', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM);
        [$member, $memberM] = linkedAllocationMember('Cotton Pants', 'CP');
        $offer = linkedAllocationOffer($member, $memberM, '900.00');
        $allocate = fn () => app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::SupplierOffer, $offer->public_id, $this->staff, 'Confirmed by staff.');

        $first = $allocate();
        $second = $allocate();

        expect($second->id)->toBe($first->id)
            ->and(OrderItemAllocation::query()->count())->toBe(1)
            ->and(SupplierPayable::query()->count())->toBe(1)
            ->and($offer->stock->refresh()->reserved_quantity)->toBe(2);
    });

    it('leaves a historical allocation, its snapshot and its payable untouched when Products are unlinked later', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM);
        [$member, $memberM] = linkedAllocationMember('Cotton Pants', 'CP');
        $offer = linkedAllocationOffer($member, $memberM, '900.00');
        $allocation = app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::SupplierOffer, $offer->public_id, $this->staff, 'Confirmed by staff.');

        // Unlinking is not blocked by the allocation, and does not reach it.
        $this->links->unlink($this->staff, ProductLink::query()->active()->firstOrFail(), 'Mistake.');

        $allocation->refresh();

        expect($allocation->source_product_id)->toBe($member->id)
            ->and($allocation->source_match_kind)->toBe('linked_product')
            ->and($allocation->unit_cost->toDecimal())->toBe('900.00')
            ->and(SupplierPayable::query()->sole()->gross_amount->toDecimal())->toBe('1800.00')
            ->and(SupplierPayable::query()->sole()->status)->toBe(PayableStatus::Pending);

        // New allocations no longer see that source.
        expect(linkedAllocationCandidates(linkedAllocationLine($this->ordered, $this->orderedM)))->toBe([]);
    });

    // One database violation per test: Postgres aborts the transaction on the first.
    it('locks the source snapshot at the database', function () {
        $line = linkedAllocationLine($this->ordered, $this->orderedM);
        $offer = linkedAllocationOffer($this->ordered, $this->orderedM, '900.00');
        $allocation = app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::SupplierOffer, $offer->public_id, $this->staff, 'Confirmed.');
        $other = linkedAllocationProduct('Other', 'OT');

        expect(fn () => $allocation->forceFill(['source_product_id' => $other->id])->save())->toThrow(QueryException::class);
    });
});

describe('the candidates endpoint', function () {
    beforeEach(function () {
        $this->line = linkedAllocationLine($this->ordered, $this->orderedM);
        [$this->member, $this->memberM] = linkedAllocationMember('Cotton Pants', 'CP');

        $this->cheap = linkedAllocationOffer($this->member, $this->memberM, '780.00', stock: 4, supplier: Supplier::factory()->create(['status' => SupplierStatus::Approved, 'business_name' => 'Alpha Textiles']));
        $this->dear = linkedAllocationOffer($this->ordered, $this->orderedM, '950.00', stock: 30, supplier: Supplier::factory()->create(['status' => SupplierStatus::Approved, 'business_name' => 'Beta Garments']));
        $this->short = linkedAllocationOffer($this->member, $this->memberM, '850.00', stock: 1, supplier: Supplier::factory()->create(['status' => SupplierStatus::Approved, 'business_name' => 'Gamma Mills']));
        $this->stock = linkedAllocationWarehouse($this->ordered, $this->orderedM, 10);

        $this->cheap->forceFill(['lead_time_days' => 7])->save();
        $this->dear->forceFill(['lead_time_days' => 2])->save();
    });

    function linkedAllocationSources(array $query = []): array
    {
        return test()->actingAs(test()->staff)->getJson(
            route('admin.orders.lines.sources', ['order' => test()->line->order->public_id, 'item' => test()->line->public_id, ...$query]),
        )->assertOk()->json();
    }

    it('returns the network\'s candidates and how many linked Products contributed', function () {
        $body = linkedAllocationSources();

        expect($body['candidates'])->toHaveCount(4)
            ->and($body['sourcing'])->toBe(['linked_product_count' => 1]);
    });

    it('filters by Supplier, Warehouse, supply mode, availability and search, and sorts only on request', function () {
        $supplierId = $this->dear->supplier->public_id;

        expect(collect(linkedAllocationSources(['source_type' => 'warehouse'])['candidates'])->pluck('source_type')->unique()->all())->toBe(['warehouse'])
            ->and(linkedAllocationSources(['source_type' => 'supplier_offer'])['candidates'])->toHaveCount(3)
            ->and(linkedAllocationSources(['availability' => 'available'])['candidates'])->toHaveCount(3)
            ->and(collect(linkedAllocationSources(['search' => 'beta'])['candidates'])->pluck('supplier_name')->all())->toBe(['Beta Garments'])
            ->and(collect(linkedAllocationSources(['supplier' => $supplierId])['candidates'])->pluck('supplier_name')->all())->toBe(['Beta Garments'])
            ->and(linkedAllocationSources(['supply_mode' => 'pre_order'])['candidates'])->toBe([])
            ->and(linkedAllocationSources(['supply_mode' => 'ready_stock'])['candidates'])->toHaveCount(4);

        $suppliers = fn (string $sort) => collect(linkedAllocationSources(['source_type' => 'supplier_offer', 'sort' => $sort])['candidates']);

        expect($suppliers('cost_asc')->pluck('unit_cost.amount')->all())->toBe(['780.00', '850.00', '950.00'])
            ->and($suppliers('cost_desc')->pluck('unit_cost.amount')->all())->toBe(['950.00', '850.00', '780.00'])
            ->and($suppliers('availability_desc')->pluck('supplier_name')->first())->toBe('Beta Garments')
            ->and($suppliers('lead_time_asc')->pluck('supplier_name')->all())->toBe(['Beta Garments', 'Alpha Textiles', 'Gamma Mills'])
            ->and($suppliers('name_asc')->pluck('supplier_name')->all())->toBe(['Alpha Textiles', 'Beta Garments', 'Gamma Mills']);

        // Browsing and sorting allocate nothing.
        expect(OrderItemAllocation::query()->count())->toBe(0);
    });

    it('keeps the catalogue-wide search inside the network for a linked Product only', function () {
        $loose = linkedAllocationProduct('Loose Product', 'LP');
        $unmatched = linkedAllocationLine($loose, null);

        $body = $this->actingAs($this->staff)->getJson(route('admin.orders.lines.sources', ['order' => $unmatched->order->public_id, 'item' => $unmatched->public_id]))->assertOk()->json();

        expect($body['sourcing'])->toBe(['linked_product_count' => 0]);

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
});
