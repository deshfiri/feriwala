<?php

use App\Domain\Cases\Queries\CaseLinks;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnCaseType;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Support\StatusHistory\StatusChange;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The original Order, the return or exchange case, its Exchange Order and the
 * exchanges before and after it point at one another (D35) — and only where the
 * reader may open the other end. A reference they may not open is plain text, so
 * no screen leads to a refusal.
 */
beforeEach(function () {
    caseTestSetUp();

    $this->stranger = websiteTestAccount();
});

/** An exchange taken far enough for its replacement to exist. */
function exchangeLinksCase(): OrderReturn
{
    return caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 1, ReturnCaseType::Exchange), ReturnStatus::InspectionApproved);
}

describe('for staff', function () {
    it('links an exchange to its original order and its Exchange Order, and opens that Exchange Order', function () {
        $case = exchangeLinksCase();
        $replacement = Order::query()->findOrFail($case->refresh()->exchange_order_id);

        $this->actingAs($this->orderManager)->get(route('admin.cases.returns.show', $case->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('case.order.url', route('admin.orders.show', $case->order->public_id))
                ->where('case.exchange_order.reference', $replacement->reference)
                ->where('case.exchange_order.url', route('admin.orders.show', $replacement->public_id)));

        // The link opens the real Order detail — one implementation, for every source.
        $this->actingAs($this->orderManager)->get(route('admin.orders.show', $replacement->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/orders/show')
                ->where('order.source', 'exchange')
                ->where('exchange_origin.case.reference', $case->exchange_number)
                ->where('exchange_origin.case.url', route('admin.cases.returns.show', $case->public_id))
                ->where('exchange_origin.original_order.url', route('admin.orders.show', $case->order->public_id)));
    });

    it('gives an ordinary order no exchange origin', function () {
        $order = caseTestDeliveredOrder();

        $this->actingAs($this->orderManager)->get(route('admin.orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('exchange_origin', null));
    });

    it('shows a reference as plain text, with no address, to staff who may not open Orders', function () {
        $case = exchangeLinksCase();
        $caseOnly = caseTestStaffWith(['return.view', 'exchange.view']);

        $this->actingAs($caseOnly)->get(route('admin.cases.returns.show', $case->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('case.order.url', null)
                ->where('case.exchange_order.url', null)
                ->where('case.exchange_order.reference', Order::query()->findOrFail($case->exchange_order_id)->reference));

        // …and the page those links would have opened is refused to them, as it always was.
        $this->actingAs($caseOnly)->get(route('admin.orders.show', Order::query()->findOrFail($case->exchange_order_id)->public_id))->assertForbidden();
    });

    it('links an exchange to the one before it and the one after it', function () {
        $first = exchangeLinksCase();
        $replacement = Order::query()->findOrFail($first->refresh()->exchange_order_id);

        foreach ([OrderStatus::ReadyForFulfillment, OrderStatus::Picking, OrderStatus::Packing, OrderStatus::ReadyForPickup, OrderStatus::CourierAssigned, OrderStatus::Shipped, OrderStatus::Delivered] as $status) {
            $replacement->moveTo($status, new StatusChange(reason: 'Moved by the test.'), OrderStatusChangeSource::System);
        }

        $second = caseTestOpenReturn($replacement->refresh(), 1, ReturnCaseType::Exchange);

        $this->actingAs($this->orderManager)->get(route('admin.cases.returns.show', $second->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('case.previous_exchange.reference', $first->exchange_number)
                ->where('case.previous_exchange.url', route('admin.cases.returns.show', $first->public_id))
                ->where('case.next_exchange', null));

        $this->actingAs($this->orderManager)->get(route('admin.cases.returns.show', $first->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('case.next_exchange.reference', $second->exchange_number)
                ->where('case.next_exchange.url', route('admin.cases.returns.show', $second->public_id)));

        // The Partner follows the same chain through their own pages.
        $this->actingAs($this->partner)->get(route('cases.show', $second->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('case.links.previous_exchange.url', route('cases.show', $first->public_id))
                ->where('case.links.order.url', route('store-orders.show', $replacement->public_id)));
    });
});

describe('for the Partner', function () {
    it('links the case to its orders and opens the Exchange Order, with a way back', function () {
        $case = exchangeLinksCase();
        $replacement = Order::query()->findOrFail($case->refresh()->exchange_order_id);

        $this->actingAs($this->partner)->get(route('cases.show', $case->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('case.links.order.url', route('store-orders.show', $case->order->public_id))
                ->where('case.links.exchange_order.url', route('store-orders.show', $replacement->public_id))
                ->where('case.links.exchange_order.reference', $replacement->reference));

        $this->actingAs($this->partner)->get(route('store-orders.show', $replacement->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('exchange_origin.case.url', route('cases.show', $case->public_id))
                ->where('exchange_origin.original_order.url', route('store-orders.show', $case->order->public_id)));
    });

    it('is never an address into another Partner\'s orders or cases', function () {
        $case = exchangeLinksCase();
        $replacement = Order::query()->findOrFail($case->refresh()->exchange_order_id);

        $this->actingAs($this->stranger->owner)->get(route('store-orders.show', $replacement->public_id))->assertNotFound();
        $this->actingAs($this->stranger->owner)->get(route('cases.show', $case->public_id))->assertNotFound();

        // And a link is built inside the account: another account's neighbour in a chain is not offered.
        $links = app(CaseLinks::class)->forPartner($case->refresh());

        expect($links['exchange_order']['url'])->toBe(route('store-orders.show', $replacement->public_id))
            ->and($links['previous_exchange'])->toBeNull();
    });

    it('does not show an exchange origin on an ordinary order', function () {
        $this->actingAs($this->partner)->get(route('store-orders.show', caseTestDeliveredOrder()->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('exchange_origin', null));
    });
});
