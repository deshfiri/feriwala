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
 * order.edit alone used to be enough to see a Supplier's confidential rate
 * and identity, and the platform's own cost and margin, in the allocation
 * panel. This closes that gap: viewing the comparison, and assigning a
 * source, each answer to the specific permission the figures they expose
 * belong to (supplier_pricing.view, catalog.view) as well as order.edit --
 * never order.edit alone, and never a redacted list mistaken for the whole
 * picture.
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

    $this->offer = supplierTestOffer(
        Supplier::factory()->create(['status' => SupplierStatus::Approved, 'business_name' => 'Confidential Supplier Ltd']),
        $this->product,
        supplierRate: '900.00',
    );
    $this->offer->stock()->update(['quantity' => 10]);
    supplierTestOfferPriceVersion($this->offer);

    $this->warehouse = Warehouse::create(['name' => 'Dhaka Central', 'code' => 'DHK-'.random_int(100, 999), 'is_active' => true]);
    StockItem::create([
        'warehouse_id' => $this->warehouse->id,
        'product_id' => $this->product->id,
        'product_variant_id' => null,
        'available' => 10,
    ]);
});

/**
 * A bare staff login (no role, no 2FA requirement — order.edit,
 * supplier_pricing.view and catalog.view are all non-sensitive verbs) with
 * exactly the permissions named.
 */
function allocationTestStaff(array $permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function orderEditPermission(): string
{
    return PermissionCatalogue::name(PermissionModule::Order, PermissionAction::Edit);
}

function supplierPricingPermission(): string
{
    return PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::View);
}

function catalogPermission(): string
{
    return PermissionCatalogue::name(PermissionModule::Catalog, PermissionAction::View);
}

it('refuses the comparison panel to an order editor without Supplier pricing permission', function () {
    $staff = allocationTestStaff([orderEditPermission(), catalogPermission()]);

    $this->actingAs($staff)
        ->getJson(route('admin.orders.lines.sources', [$this->order->public_id, $this->line->public_id]))
        ->assertForbidden();
});

it('lets an order editor without Supplier pricing still allocate to the warehouse, but never to a Supplier', function () {
    $staff = allocationTestStaff([orderEditPermission(), catalogPermission()]);

    $this->actingAs($staff)
        ->post(route('admin.orders.lines.allocation.store', [$this->order->public_id, $this->line->public_id]), [
            'source_type' => AllocationSourceType::Warehouse->value,
            'source_id' => $this->warehouse->public_id,
            'reason' => 'Chosen by staff after comparing sources.',
        ])
        ->assertRedirect();

    expect(OrderItemAllocation::query()->sole()->source_type)->toBe(AllocationSourceType::Warehouse);

    $this->actingAs($staff)
        ->post(route('admin.orders.lines.allocation.store', [$this->order->public_id, $this->line->public_id]), [
            'source_type' => AllocationSourceType::SupplierOffer->value,
            'source_id' => $this->offer->public_id,
            'reason' => 'Chosen by staff after comparing sources.',
        ])
        ->assertForbidden();

    // Still exactly the warehouse allocation from above — the refused
    // request changed nothing.
    expect(OrderItemAllocation::query()->count())->toBe(1)
        ->and(OrderItemAllocation::query()->sole()->source_type)->toBe(AllocationSourceType::Warehouse);
});

it('refuses a Supplier pricing viewer who does not hold order.edit', function () {
    $staff = allocationTestStaff([supplierPricingPermission(), catalogPermission()]);

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

it('lets a staff member holding all three permissions view and allocate either source', function () {
    $staff = allocationTestStaff([orderEditPermission(), supplierPricingPermission(), catalogPermission()]);

    $candidates = $this->actingAs($staff)
        ->getJson(route('admin.orders.lines.sources', [$this->order->public_id, $this->line->public_id]))
        ->assertOk()
        ->json('candidates');

    expect(collect($candidates)->pluck('source_type')->unique()->sort()->values()->all())
        ->toBe(['supplier_offer', 'warehouse']);

    $this->actingAs($staff)
        ->post(route('admin.orders.lines.allocation.store', [$this->order->public_id, $this->line->public_id]), [
            'source_type' => AllocationSourceType::SupplierOffer->value,
            'source_id' => $this->offer->public_id,
            'reason' => 'Chosen by staff after comparing sources.',
        ])
        ->assertRedirect();

    expect(OrderItemAllocation::query()->sole()->source_type)->toBe(AllocationSourceType::SupplierOffer);
});

it('lets Super Admin through with no explicit grants at all', function () {
    // SuperAdmin::requiresTwoFactor() is unconditionally true (it grants
    // everything), so the admin panel itself is unreachable without it —
    // nothing to do with the allocation permissions this test is about.
    $superAdmin = User::factory()->withTwoFactor()->create();
    $superAdmin->assignRole(PlatformRole::SuperAdmin->value);

    $this->actingAs($superAdmin)
        ->getJson(route('admin.orders.lines.sources', [$this->order->public_id, $this->line->public_id]))
        ->assertOk()
        ->assertJsonPath('candidates.0.supplier_name', fn ($value) => $value === null || is_string($value));

    $this->actingAs($superAdmin)
        ->post(route('admin.orders.lines.allocation.store', [$this->order->public_id, $this->line->public_id]), [
            'source_type' => AllocationSourceType::SupplierOffer->value,
            'source_id' => $this->offer->public_id,
            'reason' => 'Chosen by staff after comparing sources.',
        ])
        ->assertRedirect();

    expect(OrderItemAllocation::query()->count())->toBe(1);
});

it('never lets a Client/Partner identity through, however many of the exact permissions it is handed directly', function () {
    $account = testBusinessAccount();
    $owner = $account->owner;
    $owner->givePermissionTo(orderEditPermission());
    $owner->givePermissionTo(supplierPricingPermission());
    $owner->givePermissionTo(catalogPermission());

    $this->actingAs($owner)
        ->getJson(route('admin.orders.lines.sources', [$this->order->public_id, $this->line->public_id]))
        ->assertForbidden();

    $this->actingAs($owner)
        ->post(route('admin.orders.lines.allocation.store', [$this->order->public_id, $this->line->public_id]), [
            'source_type' => AllocationSourceType::SupplierOffer->value,
            'source_id' => $this->offer->public_id,
            'reason' => 'Chosen by staff after comparing sources.',
        ])
        ->assertForbidden();
});

it('never lets a Supplier-guard session reach the admin allocation routes at all', function () {
    $supplier = Supplier::factory()->create();
    supplierTestSignIn($supplier);

    // The default 'web' guard sees no session here; a Supplier session is
    // never the same authenticated identity (D25) — a guest redirect, not
    // a 403 carrying data.
    $this->getJson(route('admin.orders.lines.sources', [$this->order->public_id, $this->line->public_id]))
        ->assertUnauthorized();
});

it('carries no sensitive field in a 403 from the comparison endpoint', function () {
    $staff = allocationTestStaff([orderEditPermission()]);

    $response = $this->actingAs($staff)
        ->getJson(route('admin.orders.lines.sources', [$this->order->public_id, $this->line->public_id]))
        ->assertForbidden();

    expect($response->json())->not->toHaveKey('candidates');
    expect($response->getContent())->not->toContain('Confidential Supplier Ltd')
        ->not->toContain('900.00');
});

it('redacts Supplier identity and rate from the order screen for a viewer without supplier_pricing.view, but shows warehouse cost with catalog.view', function () {
    $allocator = allocationTestStaff([orderEditPermission(), supplierPricingPermission(), catalogPermission()]);

    $this->actingAs($allocator)->post(
        route('admin.orders.lines.allocation.store', [$this->order->public_id, $this->line->public_id]),
        [
            'source_type' => AllocationSourceType::SupplierOffer->value,
            'source_id' => $this->offer->public_id,
            'reason' => 'Chosen by staff after comparing sources.',
        ],
    );

    // order.view only — no pricing permission of any kind.
    $viewer = allocationTestStaff([PermissionCatalogue::name(PermissionModule::Order, PermissionAction::View)]);

    $this->actingAs($viewer)
        ->get(route('admin.orders.show', $this->order->public_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.lines.0.allocation.source_label', null)
            ->where('order.lines.0.allocation.unit_cost', null)
            ->where('order.lines.0.allocation.expected_margin', null));

    // The same viewer, now also holding catalog.view but still not
    // supplier_pricing.view: warehouse figures would show, Supplier
    // figures still would not. Re-allocate to the warehouse to prove it.
    $this->actingAs($allocator)->post(
        route('admin.orders.lines.allocation.store', [$this->order->public_id, $this->line->public_id]),
        [
            'source_type' => AllocationSourceType::Warehouse->value,
            'source_id' => $this->warehouse->public_id,
            'reason' => 'Reallocated for the redaction check.',
        ],
    );

    $viewer->givePermissionTo(catalogPermission());

    $this->actingAs($viewer)
        ->get(route('admin.orders.show', $this->order->public_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.lines.0.allocation.source_label', $this->warehouse->name)
            ->where('order.lines.0.allocation.unit_cost.amount', '700.00'));
});
