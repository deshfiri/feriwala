<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * A Supplier's own fulfilment self-service workspace (Advanced Order
 * Management batch, Commit 2): only their own commitments, confirm/decline/
 * start-preparing/mark-ready all reachable through the advance route,
 * decline needs a reason, and cross-supplier isolation holds.
 */

function supplierFulfilmentWorkspaceCommitment(Supplier $supplier, string $productName = 'Workspace Test Product'): SupplierFulfilmentCommitment
{
    $product = websiteTestProduct(['name' => $productName]);

    $offer = SupplierOffer::create([
        'supplier_id' => $supplier->id,
        'product_id' => $product->id,
        'status' => OfferStatus::Active,
        'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT),
        'platform_rate' => Money::fromDecimal('700.00', Currency::BDT),
        'currency_code' => 'BDT',
        'activated_at' => now(),
        'supply_mode' => SupplyMode::OnDemand->value,
        'fulfilment_capacity' => null,
        'lead_time_days' => null,
    ]);
    supplierTestOfferPriceVersion($offer);

    $order = Order::factory()->create();

    $line = OrderItem::create([
        'order_id' => $order->id,
        'line_number' => 1,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'product_name' => $product->name,
        'quantity' => 1,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('700.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('700.00', Currency::BDT),
        'line_total' => Money::fromDecimal('700.00', Currency::BDT),
        'created_at' => now(),
    ]);

    $staff = testPlatformStaff(PlatformRole::Admin);

    app(AllocateOrderLineSource::class)->handle(
        $line, AllocationSourceType::SupplierOffer, $offer->public_id, $staff, 'On-demand allocation.',
    );

    return SupplierFulfilmentCommitment::query()->whereHas('offer', fn ($query) => $query->where('supplier_id', $supplier->id))->sole();
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);
    $this->commitment = supplierFulfilmentWorkspaceCommitment($this->supplier);
});

test('a Supplier sees only their own commitments on the index', function () {
    $other = Supplier::factory()->create(['status' => SupplierStatus::Approved]);
    supplierFulfilmentWorkspaceCommitment($other, 'Another Supplier Product');

    $this->actingAs($this->supplier, 'supplier')
        ->get(route('supplier.fulfilment.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('commitments.data', 1)
            ->where('commitments.data.0.id', $this->commitment->public_id));
});

test('a Supplier cannot open or act on another Supplier\'s commitment', function () {
    $other = Supplier::factory()->create(['status' => SupplierStatus::Approved]);

    $this->actingAs($other, 'supplier')
        ->get(route('supplier.fulfilment.show', $this->commitment->public_id))
        ->assertNotFound();

    $this->actingAs($other, 'supplier')
        ->post(route('supplier.fulfilment.advance', $this->commitment->public_id), ['action' => 'confirm'])
        ->assertNotFound();
});

test('confirm, start preparing and mark ready are all reachable through the advance route', function () {
    $this->actingAs($this->supplier, 'supplier')
        ->post(route('supplier.fulfilment.advance', $this->commitment->public_id), ['action' => 'confirm'])
        ->assertSessionHasNoErrors();

    expect($this->commitment->refresh()->status->value)->toBe('confirmed')
        ->and($this->commitment->confirmed_by)->toBeNull();

    $this->actingAs($this->supplier, 'supplier')
        ->post(route('supplier.fulfilment.advance', $this->commitment->public_id), ['action' => 'start_preparing'])
        ->assertSessionHasNoErrors();

    expect($this->commitment->refresh()->status->value)->toBe('preparing');

    $this->actingAs($this->supplier, 'supplier')
        ->post(route('supplier.fulfilment.advance', $this->commitment->public_id), ['action' => 'mark_ready'])
        ->assertSessionHasNoErrors();

    expect($this->commitment->refresh()->status->value)->toBe('ready')
        ->and($this->commitment->status->isTerminal())->toBeTrue();
});

test('declining is refused without a reason and recorded with the Supplier as its source', function () {
    $this->actingAs($this->supplier, 'supplier')
        ->post(route('supplier.fulfilment.advance', $this->commitment->public_id), ['action' => 'decline'])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->supplier, 'supplier')
        ->post(route('supplier.fulfilment.advance', $this->commitment->public_id), [
            'action' => 'decline',
            'reason' => 'Can no longer source this item.',
        ])
        ->assertSessionHasNoErrors();

    $this->commitment->refresh();

    expect($this->commitment->status->value)->toBe('cancelled')
        ->and($this->commitment->cancelled_by)->toBeNull()
        ->and($this->commitment->cancelled_reason)->toBe('Can no longer source this item.');

    // statusHistory() already orders ascending by id (chronological display
    // everywhere else) -- an extra ->latest('id') only appends a redundant
    // secondary sort key and never overrides it, so the last item of the
    // existing ascending order is the correct way to reach the latest row.
    $change = $this->commitment->statusHistory->last();
    expect($change->source->value)->toBe('supplier')
        ->and($change->changed_by)->toBeNull();
});

test('a Supplier never sees fail as an available action', function () {
    $this->actingAs($this->supplier, 'supplier')
        ->post(route('supplier.fulfilment.advance', $this->commitment->public_id), ['action' => 'confirm']);

    $this->actingAs($this->supplier, 'supplier')
        ->get(route('supplier.fulfilment.show', $this->commitment->public_id))
        ->assertInertia(fn ($page) => $page
            ->where('commitment.available_actions', fn ($actions) => ! collect($actions)->contains('fail')));
});
