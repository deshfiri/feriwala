<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Supplier\Actions\AdvanceSupplierFulfilmentCommitment;
use App\Domain\Supplier\Enums\FulfilmentCommitmentStatus;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Every transition a Supplier's fulfilment commitment can make (Supplier
 * Bulk Product Listing batch, correction 7).
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function fulfilmentCommitmentStateTestOne(): SupplierFulfilmentCommitment
{
    $staff = testPlatformStaff(PlatformRole::Admin);
    $supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);
    $product = websiteTestProduct();

    $offer = SupplierOffer::create([
        'supplier_id' => $supplier->id,
        'product_id' => $product->id,
        'status' => OfferStatus::Active,
        'supplier_rate' => Money::fromDecimal('900.00', Currency::BDT),
        'platform_rate' => Money::fromDecimal('1300.00', Currency::BDT),
        'currency_code' => 'BDT',
        'activated_at' => now(),
        'supply_mode' => SupplyMode::OnDemand->value,
        'lead_time_days' => 3,
    ]);
    supplierTestOfferPriceVersion($offer);

    $order = Order::factory()->create();
    $line = OrderItem::create([
        'order_id' => $order->id, 'line_number' => 1, 'product_id' => $product->id,
        'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => 1,
        'currency_code' => 'BDT', 'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('1300.00', Currency::BDT), 'line_total' => Money::fromDecimal('1300.00', Currency::BDT),
        'created_at' => now(),
    ]);

    app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::SupplierOffer, $offer->public_id, $staff, 'Test allocation.');

    return SupplierFulfilmentCommitment::query()->sole();
}

test('the full happy path: awaiting confirmation, confirmed, preparing, ready', function () {
    $commitment = fulfilmentCommitmentStateTestOne();
    $staff = testPlatformStaff(PlatformRole::Admin);
    $action = app(AdvanceSupplierFulfilmentCommitment::class);

    expect($commitment->status)->toBe(FulfilmentCommitmentStatus::AwaitingConfirmation);

    $commitment = $action->confirm($commitment, $staff);
    expect($commitment->status)->toBe(FulfilmentCommitmentStatus::Confirmed)
        ->and($commitment->confirmed_by)->toBe($staff->id)
        ->and($commitment->confirmed_at)->not->toBeNull();

    $commitment = $action->startPreparing($commitment, $staff);
    expect($commitment->status)->toBe(FulfilmentCommitmentStatus::Preparing);

    $commitment = $action->markReady($commitment, $staff);
    expect($commitment->status)->toBe(FulfilmentCommitmentStatus::Ready)
        ->and($commitment->status->isTerminal())->toBeTrue();
});

test('a confirmed commitment can fail instead of being prepared', function () {
    $commitment = fulfilmentCommitmentStateTestOne();
    $staff = testPlatformStaff(PlatformRole::Admin);
    $action = app(AdvanceSupplierFulfilmentCommitment::class);

    $commitment = $action->confirm($commitment, $staff);
    $commitment = $action->fail($commitment, $staff, 'Supplier can no longer source this.');

    expect($commitment->status)->toBe(FulfilmentCommitmentStatus::Failed)
        ->and($commitment->failed_reason)->toBe('Supplier can no longer source this.')
        ->and($commitment->failed_at)->not->toBeNull();
});

test('a commitment awaiting confirmation can be cancelled directly', function () {
    $commitment = fulfilmentCommitmentStateTestOne();
    $staff = testPlatformStaff(PlatformRole::Admin);

    $commitment = app(AdvanceSupplierFulfilmentCommitment::class)->cancel($commitment, $staff, 'Order cancelled.');

    expect($commitment->status)->toBe(FulfilmentCommitmentStatus::Cancelled)
        ->and($commitment->cancelled_by)->toBe($staff->id)
        ->and($commitment->cancelled_reason)->toBe('Order cancelled.');
});

test('a terminal commitment accepts no further transition', function () {
    $commitment = fulfilmentCommitmentStateTestOne();
    $staff = testPlatformStaff(PlatformRole::Admin);
    $action = app(AdvanceSupplierFulfilmentCommitment::class);

    $commitment = $action->cancel($commitment, $staff, 'Done.');

    expect(fn () => $action->confirm($commitment, $staff))->toThrow(IllegalStateTransition::class);
});

test('a failure or a cancellation always needs a reason', function () {
    $commitment = fulfilmentCommitmentStateTestOne();
    $staff = testPlatformStaff(PlatformRole::Admin);
    $action = app(AdvanceSupplierFulfilmentCommitment::class);

    expect(fn () => $action->fail($commitment, $staff, '   '))->toThrow(InvalidArgumentException::class);
    expect(fn () => $action->cancel($commitment, $staff, ''))->toThrow(InvalidArgumentException::class);
});
