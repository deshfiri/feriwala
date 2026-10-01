<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Supplier\Actions\ExpireOverdueFulfilmentCommitments;
use App\Domain\Supplier\Enums\FulfilmentCommitmentStatus;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * The scheduled sweep over Supplier fulfilment commitments nobody confirmed
 * in time (Advanced Order Management batch, Commit 2) -- a Supplier who
 * declines or never answers must not corrupt the order, payable or
 * inventory state.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);
    $product = websiteTestProduct();

    $this->offer = SupplierOffer::create([
        'supplier_id' => $this->supplier->id,
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
    supplierTestOfferPriceVersion($this->offer);

    $this->order = Order::factory()->create();

    $this->line = OrderItem::create([
        'order_id' => $this->order->id,
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
        $this->line, AllocationSourceType::SupplierOffer, $this->offer->public_id, $staff, 'On-demand allocation.',
    );

    $this->commitment = SupplierFulfilmentCommitment::query()->sole();
});

test('a commitment past its confirmation deadline is cancelled and the line returns to staff review', function () {
    $this->commitment->forceFill(['confirmation_due_at' => now()->subHour()])->save();

    $expired = app(ExpireOverdueFulfilmentCommitments::class)->handle();

    expect($expired)->toBe(1);

    $this->commitment->refresh();
    expect($this->commitment->status)->toBe(FulfilmentCommitmentStatus::Cancelled)
        ->and($this->commitment->cancelled_by)->toBeNull()
        ->and($this->commitment->cancelled_reason)->not->toBeNull();

    $allocation = $this->commitment->allocation->fresh();
    expect($allocation->status)->toBe(AllocationStatus::Released);

    $payable = $allocation->payable->fresh();
    expect($payable->status)->toBe(PayableStatus::Cancelled);

    expect($this->order->fresh()->fulfillment_status)->toBe(OrderFulfillmentStatus::SourceAllocationPending);
});

test('a commitment not yet due is left untouched', function () {
    $this->commitment->forceFill(['confirmation_due_at' => now()->addDay()])->save();

    $expired = app(ExpireOverdueFulfilmentCommitments::class)->handle();

    expect($expired)->toBe(0)
        ->and($this->commitment->refresh()->status)->toBe(FulfilmentCommitmentStatus::AwaitingConfirmation);
});

test('re-running the sweep is a no-op once a commitment has already expired', function () {
    $this->commitment->forceFill(['confirmation_due_at' => now()->subHour()])->save();

    app(ExpireOverdueFulfilmentCommitments::class)->handle();
    $secondPass = app(ExpireOverdueFulfilmentCommitments::class)->handle();

    expect($secondPass)->toBe(0);
});
