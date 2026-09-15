<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\Order;
use App\Support\StatusHistory\StatusChange;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The platform's review of orders (§18.4, §18.5).
 *
 * Staff who hold `order.view` read every order, with what the buyer's own page
 * leaves out: why an order is held, the staff notes on its timeline. Cancelling
 * one nobody has paid for is `order.edit`, takes a reason, and is audited. Owning
 * a business is never a way in — not for the order's own buyer, and not for staff
 * who also trade on the platform.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->karim = testBusinessAccount(AccountStatus::Active);
    $this->rahim = testBusinessAccount(AccountStatus::Active);
});

/**
 * An order held for review after its payment settled, with a staff note.
 */
function adminOrderHeld(Order $order): Order
{
    $order->forceFill(['held_at' => now(), 'hold_reason' => 'The payment settled, but the stock could not be committed. HOLD-DETAIL']);
    $order->moveTo(
        OrderStatus::OnHold,
        new StatusChange(reason: 'Stock could not be committed.', internalNote: 'INTERNAL-HOLD-NOTE', publicNote: 'orders.notes.held'),
        OrderStatusChangeSource::PaymentGateway,
    );

    return $order->refresh();
}

it('lists every account\'s orders to an order manager, held orders first', function () {
    $waiting = Order::factory()->create(['business_account_id' => $this->karim->id]);
    $held = adminOrderHeld(Order::factory()->create(['business_account_id' => $this->rahim->id, 'placed_at' => now()->subDay()]));

    $this->actingAs(testPlatformStaff(PlatformRole::OrderManager))
        ->get(route('admin.orders.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/orders/index')
            ->has('orders.data', 2)
            ->where('orders.data.0.reference', $held->reference)
            ->where('orders.data.0.needs_attention', true)
            ->where('orders.data.1.reference', $waiting->reference)
            ->where('orders.data.1.account', $this->karim->name));
});

it('filters by status and finds an order by reference or business', function () {
    $held = adminOrderHeld(Order::factory()->create(['business_account_id' => $this->rahim->id]));
    Order::factory()->create(['business_account_id' => $this->karim->id]);

    $manager = testPlatformStaff(PlatformRole::OrderManager);

    $this->actingAs($manager)
        ->get(route('admin.orders.index', ['status' => 'on_hold']))
        ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.reference', $held->reference));

    $this->actingAs($manager)
        ->get(route('admin.orders.index', ['search' => $this->karim->name]))
        ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.account', $this->karim->name));
});

it('shows staff why an order is held and the notes the buyer never sees', function () {
    $order = adminOrderHeld(Order::factory()->create(['business_account_id' => $this->karim->id]));

    $this->actingAs(testPlatformStaff(PlatformRole::OrderManager))
        ->get(route('admin.orders.show', $order->public_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/orders/show')
            ->where('order.status', 'on_hold')
            ->where('order.hold_reason', 'The payment settled, but the stock could not be committed. HOLD-DETAIL')
            ->where('order.history.0.new_status', 'on_hold')
            ->where('order.history.0.internal_note', 'INTERNAL-HOLD-NOTE')
            ->where('order.history.0.source', 'payment_gateway')
            ->where('can.cancel', false));
});

it('refuses the review to staff without the order permission, and to the order\'s own buyer', function () {
    $order = Order::factory()->create(['business_account_id' => $this->karim->id]);

    foreach ([testPlatformStaff(PlatformRole::InventoryManager), $this->karim->owner] as $user) {
        $this->actingAs($user)->get(route('admin.orders.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.orders.show', $order->public_id))->assertForbidden();
        $this->actingAs($user)
            ->post(route('admin.orders.cancellation.store', $order->public_id), ['reason' => 'Trying to cancel from outside.'])
            ->assertForbidden();
    }

    expect($order->refresh()->status)->toBe(OrderStatus::PaymentPending);
});

it('refuses order administration to staff who also trade on the platform', function () {
    // A business identity never holds platform order administration (§12 and
    // §18.4 read together), whatever role has been handed to it.
    $owner = $this->rahim->owner;
    $owner->assignRole(PlatformRole::OrderManager->value);
    $owner->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $order = Order::factory()->create(['business_account_id' => $this->karim->id]);

    $this->actingAs($owner->refresh())->get(route('admin.orders.index'))->assertForbidden();
    $this->actingAs($owner)->get(route('admin.orders.show', $order->public_id))->assertForbidden();
});

it('lets a reviewer who may only read see an order but not cancel it', function () {
    $order = Order::factory()->create(['business_account_id' => $this->karim->id]);
    $reader = testPlatformStaff(PlatformRole::CourierManager);

    $this->actingAs($reader)
        ->get(route('admin.orders.show', $order->public_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.cancel', false));

    $this->actingAs($reader)
        ->post(route('admin.orders.cancellation.store', $order->public_id), ['reason' => 'Buyer asked us to cancel it.'])
        ->assertForbidden();

    expect($order->refresh()->status)->toBe(OrderStatus::PaymentPending);
});

it('lets an order manager cancel an unpaid order with a reason, kept for staff and audited', function () {
    $order = Order::factory()->create(['business_account_id' => $this->karim->id]);
    $manager = testPlatformStaff(PlatformRole::OrderManager);

    $this->actingAs($manager)
        ->get(route('admin.orders.show', $order->public_id))
        ->assertInertia(fn (Assert $page) => $page->where('can.cancel', true));

    $this->actingAs($manager)
        ->post(route('admin.orders.cancellation.store', $order->public_id), ['reason' => 'Buyer asked us to cancel by phone.'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $order->refresh();
    $change = $order->statusHistory->last();

    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->payment?->status)->toBe(PaymentStatus::Cancelled)
        ->and($change?->source)->toBe(OrderStatusChangeSource::Staff)
        ->and($change?->changed_by)->toBe($manager->id)
        ->and($change?->internal_note)->toBe('Buyer asked us to cancel by phone.')
        ->and($change?->public_note)->toBe('orders.notes.cancelled_by_staff');

    $audit = DB::table('audit_logs')->where('action', 'order.cancelled_unpaid')->sole();

    expect($audit->actor_id)->toBe($manager->id)
        ->and($audit->auditable_id)->toBe($order->id)
        ->and($audit->reason)->toBe('Buyer asked us to cancel by phone.')
        ->and((bool) $audit->is_sensitive)->toBeTrue();

    // The buyer reads that Feriwala cancelled it, never the staff note.
    $page = $this->actingAs($this->karim->owner)->get(route('wholesale.orders.show', $order->public_id));

    $page->assertInertia(fn (Assert $page) => $page->where('order.status', 'cancelled'));
    expect(json_encode($page->viewData('page')))->not->toContain('Buyer asked us to cancel by phone.');
});

it('refuses a cancellation without a proper reason, and never cancels a paid order', function () {
    $order = Order::factory()->create(['business_account_id' => $this->karim->id]);
    $manager = testPlatformStaff(PlatformRole::OrderManager);

    $this->actingAs($manager)
        ->post(route('admin.orders.cancellation.store', $order->public_id), ['reason' => 'short'])
        ->assertSessionHasErrors('reason');

    $order->payment?->transitionTo(PaymentStatus::Initiated)->save();
    $order->payment?->transitionTo(PaymentStatus::Paid)->save();

    $this->actingAs($manager)
        ->post(route('admin.orders.cancellation.store', $order->public_id), ['reason' => 'Cancelling a paid order by mistake.'])
        ->assertSessionHasErrors(['reason' => __('orders.refused.not_awaiting_payment')]);

    expect($order->refresh()->status)->toBe(OrderStatus::PaymentPending)
        ->and(DB::table('audit_logs')->where('action', 'order.cancelled_unpaid')->count())->toBe(0);
});
