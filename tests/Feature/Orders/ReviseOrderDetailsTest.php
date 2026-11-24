<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderRevision;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Editing an order's customer, addresses and note from the admin order page
 * (D38). Every edit is one applied revision with a reason; the money never
 * moves; each part answers to its own permission; the order's state decides
 * whether it may be edited at all.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function reviseDetailsPayload(Order $order, array $overrides = []): array
{
    return [
        'customer' => [...$order->customer, 'mobile' => '01811112222'],
        'shipping_address' => [...$order->shipping_address, 'line_1' => 'House 12, Road 4, Flat 3B'],
        'reason' => 'Customer called to correct the flat number and phone.',
        // As the page saw it: read from the row, where the default lives.
        'base_revision' => (int) Order::query()->whereKey($order->id)->value('revision_number'),
        'idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

function reviseDetailsSuperAdmin(): User
{
    return testPlatformStaff(PlatformRole::SuperAdmin);
}

it('lets a Super Admin correct the customer and the delivery address, as one revision', function () {
    $order = Order::factory()->create();
    $totalBefore = $order->total->toDecimal();

    $this->actingAs(reviseDetailsSuperAdmin())
        ->post(route('admin.orders.revisions.details', $order->public_id), reviseDetailsPayload($order))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $order->refresh();
    $revision = $order->revisions()->sole();

    expect($order->revision_number)->toBe(1)
        ->and($order->customer['mobile'])->toBe('01811112222')
        ->and($order->shipping_address['line_1'])->toBe('House 12, Road 4, Flat 3B')
        // What it costs and what it is never move.
        ->and($order->total->toDecimal())->toBe($totalBefore)
        ->and($revision->sections)->toBe(['customer', 'addresses'])
        ->and($revision->total_difference->toDecimal())->toBe('0.00')
        ->and($revision->before_snapshot['order']['shipping_address']['line_1'])->not->toBe('House 12, Road 4, Flat 3B')
        ->and($revision->final_snapshot['order']['shipping_address']['line_1'])->toBe('House 12, Road 4, Flat 3B');
});

it('shows the Edit order panel and the revision history on the order page', function () {
    $order = Order::factory()->create();
    $admin = reviseDetailsSuperAdmin();

    $this->actingAs($admin)->post(route('admin.orders.revisions.details', $order->public_id), reviseDetailsPayload($order));

    $this->actingAs($admin)
        ->get(route('admin.orders.show', $order->public_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/orders/show')
            ->where('revision.number', 1)
            ->where('revision.blocker', null)
            ->where('revision.can.edit_customer', true)
            ->where('revision.can.edit_address', true)
            ->where('revision.form.shipping_address.line_1', 'House 12, Road 4, Flat 3B')
            ->missing('revision.form.shipping_address.country')
            ->has('revision.history', 1)
            ->where('revision.history.0.is_current', true)
            ->where('revision.history.0.reason', 'Customer called to correct the flat number and phone.'));
});

it('keeps the customer\'s personal details out of the audit log', function () {
    $order = Order::factory()->create();

    $this->actingAs(reviseDetailsSuperAdmin())
        ->post(route('admin.orders.revisions.details', $order->public_id), reviseDetailsPayload($order));

    $log = AuditLog::query()->where('action', 'order.revised')->sole();
    $written = json_encode([$log->before, $log->after], JSON_THROW_ON_ERROR);

    expect($written)->toContain($order->reference)
        ->and($written)->toContain('shipping_address')
        ->and($written)->not->toContain('01811112222')
        ->and($written)->not->toContain('House 12');
});

it('refuses staff who hold only order.edit', function () {
    $order = Order::factory()->create();

    $this->actingAs(testPlatformStaff(PlatformRole::OrderManager))
        ->post(route('admin.orders.revisions.details', $order->public_id), reviseDetailsPayload($order))
        ->assertForbidden();

    expect($order->refresh()->revision_number)->toBe(0)
        ->and(OrderRevision::query()->count())->toBe(0);
});

it('lets an address permission change the address but not the customer', function () {
    $order = Order::factory()->create();
    $staff = testPlatformStaff(PlatformRole::OrderManager);
    $staff->givePermissionTo('order.edit_address');

    $this->actingAs($staff)
        ->post(route('admin.orders.revisions.details', $order->public_id), reviseDetailsPayload($order))
        ->assertForbidden();

    $this->actingAs($staff)
        ->post(route('admin.orders.revisions.details', $order->public_id), reviseDetailsPayload($order, [
            'customer' => $order->customer,
        ]))
        ->assertSessionHasNoErrors();

    expect($order->refresh()->shipping_address['line_1'])->toBe('House 12, Road 4, Flat 3B')
        ->and($order->customer['mobile'])->not->toBe('01811112222');
});

it('requires a reason, and never fills one in', function () {
    $order = Order::factory()->create();

    $this->actingAs(reviseDetailsSuperAdmin())
        ->post(route('admin.orders.revisions.details', $order->public_id), reviseDetailsPayload($order, ['reason' => '']))
        ->assertSessionHasErrors('reason');

    expect($order->refresh()->revision_number)->toBe(0);
});

it('refuses an edit made against a version someone else has since changed', function () {
    $order = Order::factory()->create();
    $admin = reviseDetailsSuperAdmin();
    $stale = reviseDetailsPayload($order, ['shipping_address' => [...$order->shipping_address, 'line_1' => 'The stale edit']]);

    $this->actingAs($admin)->post(route('admin.orders.revisions.details', $order->public_id), reviseDetailsPayload($order));

    $this->actingAs($admin)
        ->post(route('admin.orders.revisions.details', $order->public_id), $stale)
        ->assertSessionHasErrors(['revision' => __('orders.revision.refused.revision_changed')]);

    expect($order->refresh()->shipping_address['line_1'])->toBe('House 12, Road 4, Flat 3B');
});

it('applies a repeated submission once', function () {
    $order = Order::factory()->create();
    $admin = reviseDetailsSuperAdmin();
    $payload = reviseDetailsPayload($order);

    $this->actingAs($admin)->post(route('admin.orders.revisions.details', $order->public_id), $payload)->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('admin.orders.revisions.details', $order->public_id), $payload)->assertSessionHasNoErrors();

    expect(OrderRevision::query()->count())->toBe(1)
        ->and($order->refresh()->revision_number)->toBe(1);
});

it('refuses a revision that changes nothing', function () {
    $order = Order::factory()->create();

    $this->actingAs(reviseDetailsSuperAdmin())
        ->post(route('admin.orders.revisions.details', $order->public_id), reviseDetailsPayload($order, [
            'customer' => $order->customer,
            'shipping_address' => $order->shipping_address,
        ]))
        ->assertSessionHasErrors(['revision' => __('orders.revision.refused.nothing_changed')]);
});

it('never adds a field the order does not have, nor reaches its identity', function () {
    $order = Order::factory()->create();

    $this->actingAs(reviseDetailsSuperAdmin())
        ->post(route('admin.orders.revisions.details', $order->public_id), reviseDetailsPayload($order, [
            'shipping_address' => [...$order->shipping_address, 'line_1' => 'House 12, Road 4, Flat 3B', 'upazila' => 'Invented', 'country' => 'IN'],
            'reference' => 'ORD-REWRITTEN',
        ]))
        ->assertSessionHasNoErrors();

    $order->refresh();

    expect($order->shipping_address)->not->toHaveKey('upazila')
        ->and($order->shipping_address['country'])->toBe('BD')
        ->and($order->reference)->not->toBe('ORD-REWRITTEN');
});

it('refuses to edit an order a courier already holds, and says how to release it', function () {
    $order = Order::factory()->create();
    DB::table('orders')->where('id', $order->id)->update(['courier_status' => OrderCourierStatus::Assigned->value]);
    $admin = reviseDetailsSuperAdmin();

    $this->actingAs($admin)
        ->post(route('admin.orders.revisions.details', $order->public_id), reviseDetailsPayload($order))
        ->assertSessionHasErrors(['revision' => __('orders.revision.refused.courier_booked')]);

    $this->actingAs($admin)
        ->get(route('admin.orders.show', $order->public_id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('revision.blocker', 'courier_booked')
            ->where('revision.blocker_message', __('orders.revision.blocked.courier_booked')));
});

it('refuses to edit a cancelled order', function () {
    $order = Order::factory()->create();
    DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::Cancelled->value, 'cancelled_at' => now()]);

    $this->actingAs(reviseDetailsSuperAdmin())
        ->post(route('admin.orders.revisions.details', $order->public_id), reviseDetailsPayload($order))
        ->assertSessionHasErrors(['revision' => __('orders.revision.refused.closed')]);

    expect($order->refresh()->revision_number)->toBe(0);
});
