<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Actions\AdvanceOrderCourierStatus;
use App\Domain\Order\Actions\AdvanceOrderDeliveryStatus;
use App\Domain\Order\DispatchGate;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Delivery and the courier start only once fulfilment has packed the goods
 * (Ready for dispatch, or Fulfilled). Hold and cancel are always allowed, a track
 * already under way is never blocked, and a Store Order has its own workflow.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = testPlatformStaff(PlatformRole::Admin);
});

function dispatchGateOrder(OrderFulfillmentStatus $fulfilment, array $attributes = []): Order
{
    return Order::factory()->create(['fulfillment_status' => $fulfilment, ...$attributes]);
}

describe('the rule', function () {
    it('opens for packed goods and no earlier stage', function (OrderFulfillmentStatus $status, bool $open) {
        $order = (new Order)->forceFill(['source' => OrderSource::ErpWholesale, 'fulfillment_status' => $status]);

        expect(app(DispatchGate::class)->isOpen($order))->toBe($open);
    })->with([
        'pending review' => [OrderFulfillmentStatus::PendingReview, false],
        'allocation pending' => [OrderFulfillmentStatus::SourceAllocationPending, false],
        'supplier confirmation' => [OrderFulfillmentStatus::SupplierConfirmationPending, false],
        'processing' => [OrderFulfillmentStatus::Processing, false],
        'picking' => [OrderFulfillmentStatus::Picking, false],
        'packing' => [OrderFulfillmentStatus::Packing, false],
        'partly fulfilled' => [OrderFulfillmentStatus::PartiallyFulfilled, false],
        'on hold' => [OrderFulfillmentStatus::OnHold, false],
        'cancelled' => [OrderFulfillmentStatus::Cancelled, false],
        'ready for dispatch' => [OrderFulfillmentStatus::ReadyForDispatch, true],
        'fulfilled' => [OrderFulfillmentStatus::Fulfilled, true],
    ]);

    it('does not govern a Store Order, which has its own named workflow', function () {
        $order = (new Order)->forceFill([
            'source' => OrderSource::Website,
            'fulfillment_status' => OrderFulfillmentStatus::PendingReview,
            'delivery_status' => OrderDeliveryStatus::NotShipped,
            'courier_status' => OrderCourierStatus::Unassigned,
        ]);

        expect(app(DispatchGate::class)->isOpen($order))->toBeTrue()
            ->and(app(DispatchGate::class)->blocksDelivery($order, OrderDeliveryStatus::CourierAssigned))->toBeFalse()
            ->and(app(DispatchGate::class)->blocksCourier($order, OrderCourierStatus::Assigned))->toBeFalse();
    });
});

describe('delivery', function () {
    it('cannot start before the goods are packed, and nothing changes', function (OrderFulfillmentStatus $status) {
        $order = dispatchGateOrder($status);

        expect(fn () => app(AdvanceOrderDeliveryStatus::class)->handle($order, OrderDeliveryStatus::CourierAssigned, $this->staff))
            ->toThrow(InvalidArgumentException::class, 'Delivery and the courier can start only once fulfilment is Ready for dispatch');

        expect($order->refresh()->delivery_status)->toBe(OrderDeliveryStatus::NotShipped)
            ->and($order->deliveryStatusHistory()->count())->toBe(0);
    })->with([
        OrderFulfillmentStatus::PendingReview,
        OrderFulfillmentStatus::Processing,
        OrderFulfillmentStatus::Packing,
        OrderFulfillmentStatus::PartiallyFulfilled,
        OrderFulfillmentStatus::OnHold,
    ]);

    it('starts once fulfilment is ready for dispatch or fulfilled', function (OrderFulfillmentStatus $status) {
        $order = dispatchGateOrder($status);

        app(AdvanceOrderDeliveryStatus::class)->handle($order, OrderDeliveryStatus::CourierAssigned, $this->staff);

        expect($order->refresh()->delivery_status)->toBe(OrderDeliveryStatus::CourierAssigned);
    })->with([OrderFulfillmentStatus::ReadyForDispatch, OrderFulfillmentStatus::Fulfilled]);

    it('can still be held or cancelled, whatever fulfilment says', function (OrderDeliveryStatus $to) {
        $order = dispatchGateOrder(OrderFulfillmentStatus::PendingReview);

        app(AdvanceOrderDeliveryStatus::class)->handle($order, $to, $this->staff, 'Customer asked to stop.');

        expect($order->refresh()->delivery_status)->toBe($to);
    })->with([OrderDeliveryStatus::OnHold, OrderDeliveryStatus::Cancelled]);

    it('is not blocked once it is under way, even if fulfilment is later held', function () {
        $order = dispatchGateOrder(OrderFulfillmentStatus::OnHold, [
            'delivery_status' => OrderDeliveryStatus::Shipped,
            'courier_status' => OrderCourierStatus::InTransit,
        ]);

        app(AdvanceOrderDeliveryStatus::class)->handle($order, OrderDeliveryStatus::InTransit, $this->staff);

        expect($order->refresh()->delivery_status)->toBe(OrderDeliveryStatus::InTransit);
    });

    it('asks the freshest fulfilment status, not the one the order was loaded with', function () {
        $order = dispatchGateOrder(OrderFulfillmentStatus::ReadyForDispatch);
        $stale = Order::query()->findOrFail($order->id);

        // Fulfilment is pulled back after the page was loaded.
        Order::query()->whereKey($order->id)->toBase()->update(['fulfillment_status' => OrderFulfillmentStatus::OnHold->value]);

        expect(fn () => app(AdvanceOrderDeliveryStatus::class)->handle($stale, OrderDeliveryStatus::CourierAssigned, $this->staff))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('the courier', function () {
    it('cannot be assigned before the goods are packed', function () {
        $order = dispatchGateOrder(OrderFulfillmentStatus::Picking);

        expect(fn () => app(AdvanceOrderCourierStatus::class)->handle($order, OrderCourierStatus::Assigned, $this->staff))
            ->toThrow(InvalidArgumentException::class, 'Ready for dispatch');

        expect($order->refresh()->courier_status)->toBe(OrderCourierStatus::Unassigned);
    });

    it('is assigned once fulfilment is ready for dispatch', function () {
        $order = dispatchGateOrder(OrderFulfillmentStatus::ReadyForDispatch);

        app(AdvanceOrderCourierStatus::class)->handle($order, OrderCourierStatus::Assigned, $this->staff);

        expect($order->refresh()->courier_status)->toBe(OrderCourierStatus::Assigned);
    });

    it('can still be cancelled before fulfilment is ready', function () {
        $order = dispatchGateOrder(OrderFulfillmentStatus::Picking);

        app(AdvanceOrderCourierStatus::class)->handle($order, OrderCourierStatus::Cancelled, $this->staff, 'Not needed.');

        expect($order->refresh()->courier_status)->toBe(OrderCourierStatus::Cancelled);
    });
});

describe('creating a shipment', function () {
    function dispatchGatePackages(): array
    {
        return [['weight' => '1.500', 'length' => '20.00', 'width' => '15.00', 'height' => '10.00']];
    }

    it('is refused before the goods are packed, and leaves no shipment behind', function () {
        $order = dispatchGateOrder(OrderFulfillmentStatus::Packing);

        expect(fn () => app(CreateShipmentFromAllocations::class)->handle(
            $order,
            CourierProviderCode::Manual,
            Money::fromDecimal('80.00', Currency::BDT),
            dispatchGatePackages(),
            $this->staff,
        ))->toThrow(InvalidArgumentException::class, 'Ready for dispatch');

        expect(Shipment::query()->count())->toBe(0)
            ->and($order->refresh()->courier_status)->toBe(OrderCourierStatus::Unassigned);
    });

    it('goes ahead once the goods are packed', function () {
        $order = dispatchGateOrder(OrderFulfillmentStatus::ReadyForDispatch);

        app(CreateShipmentFromAllocations::class)->handle(
            $order,
            CourierProviderCode::Manual,
            Money::fromDecimal('80.00', Currency::BDT),
            dispatchGatePackages(),
            $this->staff,
        );

        expect($order->refresh()->courier_status)->toBe(OrderCourierStatus::Assigned)
            ->and($order->delivery_status)->toBe(OrderDeliveryStatus::CourierAssigned);
    });
});

describe('the admin screen and endpoints', function () {
    it('holds the later tracks back, says why, and offers only hold and cancel', function () {
        $order = dispatchGateOrder(OrderFulfillmentStatus::Packing);

        $this->actingAs($this->staff)
            ->get(route('admin.orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.lifecycle.fulfillment.locked_reason', null)
                ->where('order.lifecycle.delivery.locked_reason', fn ($reason) => str_contains($reason, 'Ready for dispatch'))
                ->where('order.lifecycle.delivery.available_actions', ['on_hold', 'cancelled'])
                ->where('order.lifecycle.courier.locked_reason', fn ($reason) => str_contains($reason, 'Packing'))
                ->where('order.lifecycle.courier.available_actions', ['cancelled']));
    });

    it('opens them, with nothing said, once the goods are packed', function () {
        $order = dispatchGateOrder(OrderFulfillmentStatus::ReadyForDispatch);

        $this->actingAs($this->staff)
            ->get(route('admin.orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.lifecycle.delivery.locked_reason', null)
                ->where('order.lifecycle.delivery.available_actions', ['courier_assigned', 'on_hold', 'cancelled'])
                ->where('order.lifecycle.courier.locked_reason', null)
                ->where('order.lifecycle.courier.available_actions', ['assigned', 'cancelled']));
    });

    it('refuses a request that skips ahead, with the reason beside the status', function (string $route, string $status) {
        $order = dispatchGateOrder(OrderFulfillmentStatus::Processing);

        $this->actingAs($this->staff)
            ->post(route($route, $order->public_id), ['status' => $status])
            ->assertSessionHasErrors(['status' => __('orders.admin.refused.fulfilment_first', [
                'status' => OrderFulfillmentStatus::Processing->label(),
                'needed' => OrderFulfillmentStatus::ReadyForDispatch->label(),
            ])]);

        expect($order->refresh()->delivery_status)->toBe(OrderDeliveryStatus::NotShipped)
            ->and($order->courier_status)->toBe(OrderCourierStatus::Unassigned);
    })->with([
        'delivery' => ['admin.orders.delivery-status.advance', 'courier_assigned'],
        'courier' => ['admin.orders.courier-status.advance', 'assigned'],
    ]);

    it('accepts the same request once the goods are packed', function () {
        $order = dispatchGateOrder(OrderFulfillmentStatus::ReadyForDispatch);

        $this->actingAs($this->staff)
            ->post(route('admin.orders.delivery-status.advance', $order->public_id), ['status' => 'courier_assigned'])
            ->assertSessionHasNoErrors();

        expect($order->refresh()->delivery_status)->toBe(OrderDeliveryStatus::CourierAssigned);
    });
});
