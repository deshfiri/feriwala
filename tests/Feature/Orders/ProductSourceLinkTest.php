<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Actions\ConfirmProductSourceLink;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Exceptions\AllocationRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Order\Models\ProductSourceLink;
use App\Domain\Order\Queries\AllocationSourceCandidates;
use App\Domain\Order\Queries\SearchAllocationSources;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * The Order Allocation panel correction: staff must be able to search the
 * whole eligible Supplier/Warehouse catalogue, not only sources already
 * catalogued under the ordered product, and confirm a durable, audited
 * relationship before such a source becomes allocatable.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->order = Order::factory()->create();
    $this->product = websiteTestProduct();
    $this->product->forceFill(['base_cost' => Money::fromDecimal('700.00', Currency::BDT)])->save();

    $this->line = OrderItem::create([
        'order_id' => $this->order->id,
        'line_number' => 1,
        'product_id' => $this->product->id,
        'sku' => $this->product->sku,
        'product_name' => $this->product->name,
        'quantity' => 2,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('2600.00', Currency::BDT),
        'line_total' => Money::fromDecimal('2600.00', Currency::BDT),
        'created_at' => now(),
    ]);

    // A completely unrelated product, with its own Supplier offer and its
    // own warehouse stock -- what today's exact-match query would never
    // show for this line at all.
    $this->unrelatedProduct = websiteTestProduct();

    $this->unrelatedOffer = supplierTestOffer(
        Supplier::factory()->create(['status' => SupplierStatus::Approved, 'business_name' => 'Cross Catalogue Supplier Ltd']),
        $this->unrelatedProduct,
        supplierRate: '650.00',
    );
    $this->unrelatedOffer->stock()->update(['quantity' => 10]);
    supplierTestOfferPriceVersion($this->unrelatedOffer);

    $this->unrelatedWarehouse = Warehouse::create(['name' => 'Chattogram Depot', 'code' => 'CTG-'.random_int(100, 999), 'is_active' => true]);
    $this->unrelatedStockItem = StockItem::create([
        'warehouse_id' => $this->unrelatedWarehouse->id,
        'product_id' => $this->unrelatedProduct->id,
        'product_variant_id' => null,
        'available' => 10,
    ]);
});

function linkTestStaff(array $permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function catalogEditPermission(): string
{
    return PermissionCatalogue::name(PermissionModule::Catalog, PermissionAction::Edit);
}

function supplierPricingViewPermission(): string
{
    return PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::View);
}

function catalogViewPermission(): string
{
    return PermissionCatalogue::name(PermissionModule::Catalog, PermissionAction::View);
}

function linkTestOrderEditPermission(): string
{
    return PermissionCatalogue::name(PermissionModule::Order, PermissionAction::Edit);
}

describe('searching the whole catalogue', function () {
    it('finds an unrelated Supplier offer and an unrelated warehouse stock item, both marked not related', function () {
        $results = app(SearchAllocationSources::class)->search($this->line, null, '', 1);

        $bySourceLabel = collect($results->items());

        $offerResult = $bySourceLabel->first(fn ($c) => $c->sourceType === AllocationSourceType::SupplierOffer && $c->sourceId === $this->unrelatedOffer->public_id);
        $stockResult = $bySourceLabel->first(fn ($c) => $c->sourceType === AllocationSourceType::Warehouse && $c->sourceId === $this->unrelatedStockItem->public_id);

        expect($offerResult)->not->toBeNull()
            ->and($offerResult->isRelated)->toBeFalse()
            ->and($stockResult)->not->toBeNull()
            ->and($stockResult->isRelated)->toBeFalse();
    });

    it('marks the exact-match product as related without any confirmation', function () {
        $warehouse = Warehouse::create(['name' => 'Dhaka Central', 'code' => 'DHK-'.random_int(100, 999), 'is_active' => true]);
        StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->product->id, 'product_variant_id' => null, 'available' => 5]);

        $results = app(SearchAllocationSources::class)->search($this->line, null, '', 1);
        $match = collect($results->items())->first(fn ($c) => $c->sourceType === AllocationSourceType::Warehouse && $c->sourceId === $warehouse->public_id);

        expect($match)->not->toBeNull()->and($match->isRelated)->toBeTrue();
    });

    it('filters by source type', function () {
        $warehouseOnly = app(SearchAllocationSources::class)->search($this->line, AllocationSourceType::Warehouse, '', 1);

        expect(collect($warehouseOnly->items())->every(fn ($c) => $c->sourceType === AllocationSourceType::Warehouse))->toBeTrue();
    });
});

describe('confirming a cross-catalogue relationship', function () {
    it('creates a durable link and the recommended list includes it afterwards', function () {
        $link = app(ConfirmProductSourceLink::class)->handle(
            $this->product,
            null,
            AllocationSourceType::SupplierOffer,
            $this->unrelatedOffer->public_id,
            testPlatformStaff(PlatformRole::Admin),
            'Same physical item, different catalogue listing.',
        );

        expect($link->status->value)->toBe('active')
            ->and($link->ordered_product_id)->toBe($this->product->id)
            ->and($link->supplier_offer_id)->toBe($this->unrelatedOffer->id);

        $recommended = app(AllocationSourceCandidates::class)->forLine($this->line->refresh());
        $recommendedIds = collect($recommended)->pluck('sourceId')->all();

        expect($recommendedIds)->toContain($this->unrelatedOffer->public_id);
    });

    it('reuses an existing active link instead of duplicating it', function () {
        $actor = testPlatformStaff(PlatformRole::Admin);
        $confirm = app(ConfirmProductSourceLink::class);

        $first = $confirm->handle($this->product, null, AllocationSourceType::SupplierOffer, $this->unrelatedOffer->public_id, $actor, 'First confirmation.');
        $second = $confirm->handle($this->product, null, AllocationSourceType::SupplierOffer, $this->unrelatedOffer->public_id, $actor, 'Second attempt, same source.');

        expect($second->id)->toBe($first->id)
            ->and(ProductSourceLink::query()->count())->toBe(1);
    });

    it('refuses to confirm a relationship to a source that already is this exact product', function () {
        expect(fn () => app(ConfirmProductSourceLink::class)->handle(
            $this->unrelatedProduct,
            null,
            AllocationSourceType::SupplierOffer,
            $this->unrelatedOffer->public_id,
            testPlatformStaff(PlatformRole::Admin),
            'Not a real cross-catalogue link.',
        ))->toThrow(AllocationRefused::class);

        expect(ProductSourceLink::query()->count())->toBe(0);
    });

    it('refuses to confirm a relationship to a suspended Supplier offer', function () {
        $this->unrelatedOffer->forceFill(['status' => OfferStatus::Suspended])->save();

        expect(fn () => app(ConfirmProductSourceLink::class)->handle(
            $this->product,
            null,
            AllocationSourceType::SupplierOffer,
            $this->unrelatedOffer->public_id,
            testPlatformStaff(PlatformRole::Admin),
            'Should not be linkable while suspended.',
        ))->toThrow(AllocationRefused::class);
    });

    it('links several different Suppliers to the same central product without collision', function () {
        $actor = testPlatformStaff(PlatformRole::Admin);
        $confirm = app(ConfirmProductSourceLink::class);

        $secondOffer = supplierTestOffer(Supplier::factory()->create(['status' => SupplierStatus::Approved]), $this->unrelatedProduct, supplierRate: '600.00');
        $secondOffer->stock()->update(['quantity' => 5]);
        supplierTestOfferPriceVersion($secondOffer);

        $confirm->handle($this->product, null, AllocationSourceType::SupplierOffer, $this->unrelatedOffer->public_id, $actor, 'Supplier A is the same item.');
        $confirm->handle($this->product, null, AllocationSourceType::SupplierOffer, $secondOffer->public_id, $actor, 'Supplier B is the same item too.');

        expect(ProductSourceLink::query()->where('ordered_product_id', $this->product->id)->count())->toBe(2);

        $recommended = collect(app(AllocationSourceCandidates::class)->forLine($this->line->refresh()))->pluck('sourceId');

        expect($recommended)->toContain($this->unrelatedOffer->public_id)
            ->toContain($secondOffer->public_id);
    });

    it('does not silently relate a confirmed link to a different ordered variation of the same product', function () {
        $variantProduct = websiteTestProduct();
        $variantA = $variantProduct->variants()->create([
            'sku' => $variantProduct->sku.'-A',
            'combination_key' => 'a',
            'currency_code' => 'BDT',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $variantB = $variantProduct->variants()->create([
            'sku' => $variantProduct->sku.'-B',
            'combination_key' => 'b',
            'currency_code' => 'BDT',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        app(ConfirmProductSourceLink::class)->handle(
            $variantProduct,
            $variantA,
            AllocationSourceType::SupplierOffer,
            $this->unrelatedOffer->public_id,
            testPlatformStaff(PlatformRole::Admin),
            'Linked for variant A only.',
        );

        $lineForA = OrderItem::create([
            'order_id' => $this->order->id, 'line_number' => 2, 'product_id' => $variantProduct->id,
            'product_variant_id' => $variantA->id, 'sku' => $variantA->sku, 'product_name' => $variantProduct->name,
            'quantity' => 1, 'currency_code' => 'BDT', 'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
            'line_subtotal' => Money::fromDecimal('1300.00', Currency::BDT), 'line_total' => Money::fromDecimal('1300.00', Currency::BDT),
            'created_at' => now(),
        ]);
        $lineForB = OrderItem::create([
            'order_id' => $this->order->id, 'line_number' => 3, 'product_id' => $variantProduct->id,
            'product_variant_id' => $variantB->id, 'sku' => $variantB->sku, 'product_name' => $variantProduct->name,
            'quantity' => 1, 'currency_code' => 'BDT', 'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
            'line_subtotal' => Money::fromDecimal('1300.00', Currency::BDT), 'line_total' => Money::fromDecimal('1300.00', Currency::BDT),
            'created_at' => now(),
        ]);

        $relatedForA = collect(app(AllocationSourceCandidates::class)->forLine($lineForA))->pluck('sourceId');
        $relatedForB = collect(app(AllocationSourceCandidates::class)->forLine($lineForB))->pluck('sourceId');

        expect($relatedForA)->toContain($this->unrelatedOffer->public_id)
            ->and($relatedForB)->not->toContain($this->unrelatedOffer->public_id);
    });
});

describe('allocating through a confirmed cross-catalogue relationship', function () {
    it('allocates to a linked Supplier offer, raising exactly one Pending payable at the linked rate, and reallocation cleans up exactly once', function () {
        $staff = testPlatformStaff(PlatformRole::Admin);
        app(ConfirmProductSourceLink::class)->handle($this->product, null, AllocationSourceType::SupplierOffer, $this->unrelatedOffer->public_id, $staff, 'Confirmed cross-catalogue match.');

        $allocation = app(AllocateOrderLineSource::class)->handle($this->line->refresh(), AllocationSourceType::SupplierOffer, $this->unrelatedOffer->public_id, $staff, 'Allocating via the confirmed link.');

        expect($allocation->supplier_offer_id)->toBe($this->unrelatedOffer->id)
            ->and($allocation->unit_cost->toDecimal())->toBe('650.00');

        $payable = SupplierPayable::query()->sole();
        expect($payable->status)->toBe(PayableStatus::Pending)
            ->and($payable->gross_amount->toDecimal())->toBe('1300.00'); // 650.00 * 2

        // Reallocate to the central warehouse -- the linked Supplier payable
        // must be cancelled exactly once, never left dangling.
        $warehouse = Warehouse::create(['name' => 'Dhaka Central', 'code' => 'DHK-'.random_int(100, 999), 'is_active' => true]);
        StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->product->id, 'product_variant_id' => null, 'available' => 5]);

        app(AllocateOrderLineSource::class)->handle(
            $this->line->refresh(), AllocationSourceType::Warehouse, $warehouse->public_id, $staff, 'Reallocated to the warehouse.',
            replacingAllocationId: $allocation->public_id,
        );

        expect($payable->refresh()->status)->toBe(PayableStatus::Cancelled)
            ->and(SupplierPayable::query()->where('status', PayableStatus::Pending)->count())->toBe(0);
    });

    it('allocates to a linked warehouse stock item, reserving against the linked product and creating no Supplier payable', function () {
        $staff = testPlatformStaff(PlatformRole::Admin);
        app(ConfirmProductSourceLink::class)->handle($this->product, null, AllocationSourceType::Warehouse, $this->unrelatedStockItem->public_id, $staff, 'Confirmed warehouse cross-catalogue match.');

        $allocation = app(AllocateOrderLineSource::class)->handle($this->line->refresh(), AllocationSourceType::Warehouse, $this->unrelatedStockItem->public_id, $staff, 'Allocating via the confirmed warehouse link.');

        expect($allocation->linked_stock_item_id)->toBe($this->unrelatedStockItem->id)
            ->and($allocation->warehouse_id)->toBe($this->unrelatedWarehouse->id);

        expect(SupplierPayable::query()->count())->toBe(0);
        expect($this->unrelatedStockItem->refresh()->available)->toBe(8)
            ->and($this->unrelatedStockItem->refresh()->reserved)->toBe(2);
    });

    it('refuses to allocate to a source with no confirmed relationship and no exact match', function () {
        $staff = testPlatformStaff(PlatformRole::Admin);

        expect(fn () => app(AllocateOrderLineSource::class)->handle(
            $this->line->refresh(),
            AllocationSourceType::SupplierOffer,
            $this->unrelatedOffer->public_id,
            $staff,
            'No link confirmed yet.',
        ))->toThrow(AllocationRefused::class);

        expect(OrderItemAllocation::query()->count())->toBe(0);
    });
});

describe('permissions on the search and confirm endpoints', function () {
    it('403s the search endpoint without supplier_pricing.view even with order.edit and catalog.view', function () {
        $staff = linkTestStaff([linkTestOrderEditPermission(), catalogViewPermission()]);

        $this->actingAs($staff)
            ->getJson(route('admin.orders.lines.sources.search', [$this->order->public_id, $this->line->public_id]))
            ->assertForbidden();
    });

    it('carries no Supplier identity or rate in a 403 from the search endpoint', function () {
        $staff = linkTestStaff([linkTestOrderEditPermission()]);

        $response = $this->actingAs($staff)
            ->getJson(route('admin.orders.lines.sources.search', [$this->order->public_id, $this->line->public_id]))
            ->assertForbidden();

        expect($response->json())->not->toHaveKey('candidates');
        expect($response->getContent())->not->toContain('Cross Catalogue Supplier Ltd')->not->toContain('650.00');
    });

    it('lets a fully-permitted staff member search across the whole catalogue over HTTP', function () {
        $staff = linkTestStaff([linkTestOrderEditPermission(), catalogViewPermission(), supplierPricingViewPermission()]);

        $response = $this->actingAs($staff)
            ->getJson(route('admin.orders.lines.sources.search', [$this->order->public_id, $this->line->public_id]))
            ->assertOk();

        $ids = collect($response->json('candidates'))->pluck('source_id');
        expect($ids)->toContain($this->unrelatedOffer->public_id);
    });

    it('403s confirming a Supplier relationship without supplier_pricing.view, even with catalog.edit', function () {
        $staff = linkTestStaff([catalogEditPermission()]);

        $this->actingAs($staff)
            ->postJson(route('admin.orders.lines.sources.confirm-link', [$this->order->public_id, $this->line->public_id]), [
                'source_type' => AllocationSourceType::SupplierOffer->value,
                'source_id' => $this->unrelatedOffer->public_id,
                'reason' => 'Attempting without supplier pricing permission.',
            ])
            ->assertForbidden();

        expect(ProductSourceLink::query()->count())->toBe(0);
    });

    it('403s confirming any relationship without catalog.edit', function () {
        $staff = linkTestStaff([supplierPricingViewPermission()]);

        $this->actingAs($staff)
            ->postJson(route('admin.orders.lines.sources.confirm-link', [$this->order->public_id, $this->line->public_id]), [
                'source_type' => AllocationSourceType::SupplierOffer->value,
                'source_id' => $this->unrelatedOffer->public_id,
                'reason' => 'Attempting without catalog.edit.',
            ])
            ->assertForbidden();
    });

    it('confirms a warehouse relationship with only catalog.edit, no supplier_pricing.view required', function () {
        $staff = linkTestStaff([catalogEditPermission()]);

        $this->actingAs($staff)
            ->postJson(route('admin.orders.lines.sources.confirm-link', [$this->order->public_id, $this->line->public_id]), [
                'source_type' => AllocationSourceType::Warehouse->value,
                'source_id' => $this->unrelatedStockItem->public_id,
                'reason' => 'Warehouse relationships need no Supplier pricing permission.',
            ])
            ->assertOk();

        expect(ProductSourceLink::query()->count())->toBe(1);
    });

    it('lets a staff member with catalog.edit and supplier_pricing.view confirm a Supplier relationship over HTTP', function () {
        $staff = linkTestStaff([catalogEditPermission(), supplierPricingViewPermission()]);

        $this->actingAs($staff)
            ->postJson(route('admin.orders.lines.sources.confirm-link', [$this->order->public_id, $this->line->public_id]), [
                'source_type' => AllocationSourceType::SupplierOffer->value,
                'source_id' => $this->unrelatedOffer->public_id,
                'reason' => 'Confirmed over HTTP by an authorized staff member.',
            ])
            ->assertOk();

        expect(ProductSourceLink::query()->count())->toBe(1);
    });

    it('never lets a business identity reach the search or confirm endpoints', function () {
        $account = testBusinessAccount();
        $owner = $account->owner;
        $owner->givePermissionTo([linkTestOrderEditPermission(), catalogViewPermission(), supplierPricingViewPermission(), catalogEditPermission()]);

        $this->actingAs($owner)
            ->getJson(route('admin.orders.lines.sources.search', [$this->order->public_id, $this->line->public_id]))
            ->assertForbidden();

        $this->actingAs($owner)
            ->postJson(route('admin.orders.lines.sources.confirm-link', [$this->order->public_id, $this->line->public_id]), [
                'source_type' => AllocationSourceType::SupplierOffer->value,
                'source_id' => $this->unrelatedOffer->public_id,
                'reason' => 'Should never reach here.',
            ])
            ->assertForbidden();
    });
});
