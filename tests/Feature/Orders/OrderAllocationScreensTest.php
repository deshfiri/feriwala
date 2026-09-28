<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * The staff allocation panel's own HTTP boundary: who may reach it, and what
 * it does and does not put on the wire (D25, §31.3).
 */

/**
 * Admin holds order.edit and catalog.view by default but deliberately not
 * supplier_pricing.view (D25) — Admin can oversee Suppliers without seeing
 * their confidential rate. This file is about the allocation screens
 * working end to end for a staff member who legitimately may see both
 * sides of the comparison, not about the permission boundary itself (see
 * OrderAllocationPermissionsTest.php for that), so it grants the one
 * permission no default role pairs with order.edit.
 */
function allocationScreenStaff(): User
{
    $staff = testPlatformStaff(PlatformRole::Admin);
    $staff->givePermissionTo(PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::View));

    return $staff;
}

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

    $this->offer = supplierTestOffer(
        Supplier::factory()->create(['status' => SupplierStatus::Approved]),
        $this->product,
        supplierRate: '900.00',
    );
    $this->offer->stock()->update(['quantity' => 10]);
    supplierTestOfferPriceVersion($this->offer);
});

it('lists sources for staff holding order.edit, with the Supplier Rate and identity present', function () {
    $staff = allocationScreenStaff();

    $response = $this->actingAs($staff)
        ->getJson(route('admin.orders.lines.sources', [$this->order->public_id, $this->line->public_id]))
        ->assertOk();

    $candidates = $response->json('candidates');
    $supplierCandidate = collect($candidates)->firstWhere('source_type', 'supplier_offer');

    // Staff-only figures (D25) — present here, on purpose.
    expect($supplierCandidate)->not->toBeNull()
        ->and($supplierCandidate['supplier_id'])->toBe($this->offer->supplier->public_id)
        ->and($supplierCandidate['supplier_name'])->toBe($this->offer->supplier->business_name)
        ->and($supplierCandidate['unit_cost']['amount'])->toBe('900.00');
});

it('allocates a line over HTTP and returns to the order screen', function () {
    $staff = allocationScreenStaff();

    $this->actingAs($staff)
        ->post(route('admin.orders.lines.allocation.store', [$this->order->public_id, $this->line->public_id]), [
            'source_type' => AllocationSourceType::SupplierOffer->value,
            'source_id' => $this->offer->public_id,
            'reason' => 'Chosen by staff after comparing sources.',
        ])
        ->assertRedirect();

    $allocation = OrderItemAllocation::query()->sole();

    expect($allocation->order_item_id)->toBe($this->line->id)
        ->and($allocation->supplier_offer_id)->toBe($this->offer->id);
});

it('refuses a reason under ten characters', function () {
    $staff = allocationScreenStaff();

    $this->actingAs($staff)
        ->post(route('admin.orders.lines.allocation.store', [$this->order->public_id, $this->line->public_id]), [
            'source_type' => AllocationSourceType::SupplierOffer->value,
            'source_id' => $this->offer->public_id,
            'reason' => 'too short',
        ])
        ->assertInvalid(['reason']);

    expect(OrderItemAllocation::query()->count())->toBe(0);
});

it('refuses staff who do not hold order.edit', function () {
    $staff = testPlatformStaff(PlatformRole::KycManager);

    $this->actingAs($staff)
        ->getJson(route('admin.orders.lines.sources', [$this->order->public_id, $this->line->public_id]))
        ->assertForbidden();

    $this->actingAs($staff)
        ->post(route('admin.orders.lines.allocation.store', [$this->order->public_id, $this->line->public_id]), [
            'source_type' => AllocationSourceType::SupplierOffer->value,
            'source_id' => $this->offer->public_id,
            'reason' => 'Chosen by staff after comparing sources.',
        ])
        ->assertForbidden();

    expect(OrderItemAllocation::query()->count())->toBe(0);
});

it('never lets a business identity reach the allocation endpoints, whatever permission it is handed', function () {
    // Gate::before refuses every order.* ability to a business identity
    // outright (OrderPolicy), before any permission check runs.
    $account = testBusinessAccount();
    $owner = $account->owner;
    $owner->givePermissionTo(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::Edit));

    $this->actingAs($owner)
        ->getJson(route('admin.orders.lines.sources', [$this->order->public_id, $this->line->public_id]))
        ->assertForbidden();
});

it('404s a line that does not belong to the order in the URL', function () {
    $staff = allocationScreenStaff();
    $otherOrder = Order::factory()->create();

    $this->actingAs($staff)
        ->getJson(route('admin.orders.lines.sources', [$otherOrder->public_id, $this->line->public_id]))
        ->assertNotFound();
});

it('shows the current allocation on the order detail screen without leaking it anywhere a Client or Partner reads', function () {
    $staff = allocationScreenStaff();

    $this->actingAs($staff)
        ->post(route('admin.orders.lines.allocation.store', [$this->order->public_id, $this->line->public_id]), [
            'source_type' => AllocationSourceType::SupplierOffer->value,
            'source_id' => $this->offer->public_id,
            'reason' => 'Chosen by staff after comparing sources.',
        ]);

    $this->actingAs($staff)
        ->get(route('admin.orders.show', $this->order->public_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.lines.0.allocation.source_type', 'supplier_offer')
            ->where('order.lines.0.allocation.source_label', $this->offer->supplier->business_name));
});

it('reports every warehouse candidate too, distinct from the supplier offer', function () {
    $warehouse = Warehouse::create(['name' => 'Dhaka Central', 'code' => 'DHK-1', 'is_active' => true]);
    StockItem::create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $this->product->id,
        'product_variant_id' => null,
        'available' => 10,
    ]);

    $staff = allocationScreenStaff();

    $candidates = $this->actingAs($staff)
        ->getJson(route('admin.orders.lines.sources', [$this->order->public_id, $this->line->public_id]))
        ->assertOk()
        ->json('candidates');

    expect(collect($candidates)->pluck('source_type')->unique()->sort()->values()->all())
        ->toBe(['supplier_offer', 'warehouse']);
});
