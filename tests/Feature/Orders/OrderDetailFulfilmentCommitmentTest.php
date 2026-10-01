<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Supplier\Enums\FulfilmentCommitmentStatus;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * The Order Detail screen's own view of a Supplier's fulfilment commitment
 * (Supplier Bulk Product Listing batch, UI closure): status, supply
 * details and the staff actions available from exactly where the
 * commitment is shown, gated the same way the rest of this Supplier's
 * figures already are.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->order = Order::factory()->create();
    $this->product = websiteTestProduct();
    $supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);

    $this->offer = SupplierOffer::create([
        'supplier_id' => $supplier->id,
        'product_id' => $this->product->id,
        'status' => OfferStatus::Active,
        'supplier_rate' => Money::fromDecimal('900.00', Currency::BDT),
        'platform_rate' => Money::fromDecimal('1300.00', Currency::BDT),
        'currency_code' => 'BDT',
        'activated_at' => now(),
        'supply_mode' => SupplyMode::OnDemand->value,
        'fulfilment_capacity' => 5,
        'lead_time_days' => 4,
    ]);
    supplierTestOfferPriceVersion($this->offer);

    $this->line = OrderItem::create([
        'order_id' => $this->order->id,
        'line_number' => 1,
        'product_id' => $this->product->id,
        'sku' => $this->product->sku,
        'product_name' => $this->product->name,
        'quantity' => 1,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_total' => Money::fromDecimal('1300.00', Currency::BDT),
        'created_at' => now(),
    ]);

    $this->staff = testPlatformStaff(PlatformRole::Admin);
    $this->staff->givePermissionTo(PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::View));

    app(AllocateOrderLineSource::class)->handle(
        $this->line, AllocationSourceType::SupplierOffer, $this->offer->public_id, $this->staff, 'On-demand allocation.',
    );

    $this->commitment = SupplierFulfilmentCommitment::query()->sole();
});

test('the order detail screen shows the commitment, its supply details and the manageable actions', function () {
    $this->actingAs($this->staff)
        ->get(route('admin.orders.show', $this->order->public_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.lines.0.allocations.0.fulfilment_commitment.status', 'awaiting_confirmation')
            ->where('order.lines.0.allocations.0.fulfilment_commitment.supply_mode', 'on_demand')
            ->where('order.lines.0.allocations.0.fulfilment_commitment.lead_time_days', 4)
            ->where('order.lines.0.allocations.0.fulfilment_commitment.fulfilment_capacity', 5)
            ->where('order.lines.0.allocations.0.fulfilment_commitment.is_staff_managed', true)
            ->where('order.lines.0.allocations.0.fulfilment_commitment.can_manage', true)
            ->where('order.lines.0.allocations.0.fulfilment_commitment.available_actions', ['confirm', 'cancel']));
});

test('a viewer without supplier_pricing.view sees the allocation but never the fulfilment commitment', function () {
    $viewer = User::factory()->staff()->create();
    $viewer->givePermissionTo([PermissionCatalogue::name(PermissionModule::Order, PermissionAction::View)]);

    $this->actingAs($viewer)
        ->get(route('admin.orders.show', $this->order->public_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.lines.0.allocations.0.source_label', null)
            ->where('order.lines.0.allocations.0.fulfilment_commitment', null));
});

test('a viewer who can see pricing but not manage allocations sees the commitment with no actions', function () {
    $viewer = User::factory()->staff()->create();
    $viewer->givePermissionTo([
        PermissionCatalogue::name(PermissionModule::Order, PermissionAction::View),
        PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::View),
    ]);

    $this->actingAs($viewer)
        ->get(route('admin.orders.show', $this->order->public_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.lines.0.allocations.0.fulfilment_commitment.status', 'awaiting_confirmation')
            ->where('order.lines.0.allocations.0.fulfilment_commitment.can_manage', false)
            ->where('order.lines.0.allocations.0.fulfilment_commitment.available_actions', []));
});

test('every legal transition is reachable through the advance route, not just confirm', function () {
    $this->actingAs($this->staff)->post(route('admin.orders.lines.fulfilment-commitments.advance', [
        $this->order, $this->line, $this->commitment,
    ]), ['action' => 'confirm'])->assertSessionHasNoErrors();

    expect($this->commitment->refresh()->status)->toBe(FulfilmentCommitmentStatus::Confirmed);

    $this->actingAs($this->staff)->post(route('admin.orders.lines.fulfilment-commitments.advance', [
        $this->order, $this->line, $this->commitment,
    ]), ['action' => 'start_preparing'])->assertSessionHasNoErrors();

    expect($this->commitment->refresh()->status)->toBe(FulfilmentCommitmentStatus::Preparing);

    $this->actingAs($this->staff)->post(route('admin.orders.lines.fulfilment-commitments.advance', [
        $this->order, $this->line, $this->commitment,
    ]), ['action' => 'mark_ready'])->assertSessionHasNoErrors();

    expect($this->commitment->refresh()->status)->toBe(FulfilmentCommitmentStatus::Ready)
        ->and($this->commitment->status->isTerminal())->toBeTrue();
});

test('failing or cancelling through the route is refused without a reason', function () {
    $this->actingAs($this->staff)->post(route('admin.orders.lines.fulfilment-commitments.advance', [
        $this->order, $this->line, $this->commitment,
    ]), ['action' => 'fail'])->assertSessionHasErrors('reason');

    $this->actingAs($this->staff)->post(route('admin.orders.lines.fulfilment-commitments.advance', [
        $this->order, $this->line, $this->commitment,
    ]), ['action' => 'cancel'])->assertSessionHasErrors('reason');

    expect($this->commitment->refresh()->status)->toBe(FulfilmentCommitmentStatus::AwaitingConfirmation);
});

test('failing through the route with a reason records it and shows it on the next load', function () {
    // Failed is only reachable from Confirmed or Preparing -- confirm first.
    $this->actingAs($this->staff)->post(route('admin.orders.lines.fulfilment-commitments.advance', [
        $this->order, $this->line, $this->commitment,
    ]), ['action' => 'confirm']);

    $this->actingAs($this->staff)->post(route('admin.orders.lines.fulfilment-commitments.advance', [
        $this->order, $this->line, $this->commitment,
    ]), ['action' => 'fail', 'reason' => 'Supplier can no longer source this item.'])->assertSessionHasNoErrors();

    expect($this->commitment->refresh()->status)->toBe(FulfilmentCommitmentStatus::Failed)
        ->and($this->commitment->failed_reason)->toBe('Supplier can no longer source this item.');

    $this->actingAs($this->staff)
        ->get(route('admin.orders.show', $this->order->public_id))
        ->assertInertia(fn ($page) => $page
            ->where('order.lines.0.allocations.0.fulfilment_commitment.status', 'failed')
            ->where('order.lines.0.allocations.0.fulfilment_commitment.failed_reason', 'Supplier can no longer source this item.')
            ->where('order.lines.0.allocations.0.fulfilment_commitment.available_actions', []));
});

test('the status history timeline accumulates across transitions', function () {
    $this->actingAs($this->staff)->post(route('admin.orders.lines.fulfilment-commitments.advance', [
        $this->order, $this->line, $this->commitment,
    ]), ['action' => 'confirm']);

    $history = $this->actingAs($this->staff)
        ->get(route('admin.orders.show', $this->order->public_id))
        ->viewData('page')['props']['order']['lines'][0]['allocations'][0]['fulfilment_commitment']['history'];

    expect($history)->toHaveCount(2)
        ->and(collect($history)->pluck('new_status')->all())
        ->toBe([
            FulfilmentCommitmentStatus::AwaitingConfirmation->label(),
            FulfilmentCommitmentStatus::Confirmed->label(),
        ]);
});

test('a reviewer who cannot manage fulfilment commitments is refused by the advance route', function () {
    $viewer = User::factory()->staff()->create();
    $viewer->givePermissionTo([
        PermissionCatalogue::name(PermissionModule::Order, PermissionAction::View),
        PermissionCatalogue::name(PermissionModule::Order, PermissionAction::Edit),
    ]);

    $this->actingAs($viewer)->post(route('admin.orders.lines.fulfilment-commitments.advance', [
        $this->order, $this->line, $this->commitment,
    ]), ['action' => 'confirm'])->assertForbidden();

    expect($this->commitment->refresh()->status)->toBe(FulfilmentCommitmentStatus::AwaitingConfirmation);
});
