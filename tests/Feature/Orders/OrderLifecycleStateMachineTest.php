<?php

use App\Domain\Order\Actions\AdvanceOrderCourierStatus;
use App\Domain\Order\Actions\AdvanceOrderDeliveryStatus;
use App\Domain\Order\Actions\AdvanceOrderFulfilmentStatus;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Models\User;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;

/*
 * The order fulfilment/delivery/courier lifecycle Actions (§18, §20, §21):
 * every legal transition is reachable, an illegal one is refused, hold and
 * cancel need a reason, and RollUpOrderStatus keeps the order's own status in
 * step at the one point it is unconditional -- delivery reaching Delivered.
 */

beforeEach(function () {
    $this->staff = User::factory()->staff()->create();
});

test('fulfilment status advances through the happy path and records history', function () {
    $order = Order::factory()->create()->refresh();

    expect($order->fulfillment_status)->toBe(OrderFulfillmentStatus::PendingReview);

    $advance = app(AdvanceOrderFulfilmentStatus::class);
    $advance->handle($order, OrderFulfillmentStatus::SourceAllocationPending, $this->staff);
    $advance->handle($order->refresh(), OrderFulfillmentStatus::Processing, $this->staff);
    $advance->handle($order->refresh(), OrderFulfillmentStatus::Picking, $this->staff);
    $advance->handle($order->refresh(), OrderFulfillmentStatus::Packing, $this->staff);
    $advance->handle($order->refresh(), OrderFulfillmentStatus::ReadyForDispatch, $this->staff);
    $advance->handle($order->refresh(), OrderFulfillmentStatus::Fulfilled, $this->staff);

    $order->refresh();

    expect($order->fulfillment_status)->toBe(OrderFulfillmentStatus::Fulfilled)
        ->and($order->fulfillment_status->isTerminal())->toBeTrue()
        ->and($order->fulfillmentStatusHistory()->count())->toBe(6);
});

test('delivery status advances through the happy path to Delivered', function () {
    $order = Order::factory()->create();

    $advance = app(AdvanceOrderDeliveryStatus::class);
    $advance->handle($order, OrderDeliveryStatus::CourierAssigned, $this->staff);
    $advance->handle($order->refresh(), OrderDeliveryStatus::Shipped, $this->staff);
    $advance->handle($order->refresh(), OrderDeliveryStatus::OutForDelivery, $this->staff);
    $advance->handle($order->refresh(), OrderDeliveryStatus::Delivered, $this->staff);

    expect($order->refresh()->delivery_status)->toBe(OrderDeliveryStatus::Delivered);
});

test('courier status advances through the happy path to Delivered', function () {
    $order = Order::factory()->create();

    $advance = app(AdvanceOrderCourierStatus::class);
    $advance->handle($order, OrderCourierStatus::Assigned, $this->staff);
    $advance->handle($order->refresh(), OrderCourierStatus::PickupRequested, $this->staff);
    $advance->handle($order->refresh(), OrderCourierStatus::PickedUp, $this->staff);
    $advance->handle($order->refresh(), OrderCourierStatus::InTransit, $this->staff);
    $advance->handle($order->refresh(), OrderCourierStatus::Delivered, $this->staff);

    expect($order->refresh()->courier_status)->toBe(OrderCourierStatus::Delivered);
});

test('an illegal transition on any axis throws', function () {
    $order = Order::factory()->create();

    expect(fn () => app(AdvanceOrderFulfilmentStatus::class)->handle($order, OrderFulfillmentStatus::Fulfilled, $this->staff))
        ->toThrow(IllegalStateTransition::class);

    expect(fn () => app(AdvanceOrderDeliveryStatus::class)->handle($order, OrderDeliveryStatus::Delivered, $this->staff))
        ->toThrow(IllegalStateTransition::class);

    expect(fn () => app(AdvanceOrderCourierStatus::class)->handle($order, OrderCourierStatus::Delivered, $this->staff))
        ->toThrow(IllegalStateTransition::class);
});

test('hold and cancel require a reason on every axis', function () {
    $order = Order::factory()->create();

    expect(fn () => app(AdvanceOrderFulfilmentStatus::class)->handle($order, OrderFulfillmentStatus::OnHold, $this->staff))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(AdvanceOrderDeliveryStatus::class)->handle($order, OrderDeliveryStatus::OnHold, $this->staff))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(AdvanceOrderCourierStatus::class)->handle($order, OrderCourierStatus::Cancelled, $this->staff))
        ->toThrow(InvalidArgumentException::class);

    // Given a reason, the same moves succeed.
    app(AdvanceOrderFulfilmentStatus::class)->handle($order, OrderFulfillmentStatus::OnHold, $this->staff, 'Awaiting a correction.');
    expect($order->refresh()->fulfillment_status)->toBe(OrderFulfillmentStatus::OnHold);
});

test('delivery reaching Delivered rolls the order up to Delivered', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Shipped, 'delivery_status' => OrderDeliveryStatus::Shipped]);

    $advance = app(AdvanceOrderDeliveryStatus::class);
    $advance->handle($order, OrderDeliveryStatus::OutForDelivery, $this->staff);
    $advance->handle($order->refresh(), OrderDeliveryStatus::Delivered, $this->staff);

    $order->refresh();

    expect($order->delivery_status)->toBe(OrderDeliveryStatus::Delivered)
        ->and($order->status)->toBe(OrderStatus::Delivered);
});

test('the rollup is a no-op when the order status cannot legally reach the target', function () {
    $order = Order::factory()->create(['status' => OrderStatus::New, 'delivery_status' => OrderDeliveryStatus::OutForDelivery]);

    app(AdvanceOrderDeliveryStatus::class)->handle($order, OrderDeliveryStatus::Delivered, $this->staff);

    $order->refresh();

    expect($order->delivery_status)->toBe(OrderDeliveryStatus::Delivered)
        ->and($order->status)->toBe(OrderStatus::New);
});
