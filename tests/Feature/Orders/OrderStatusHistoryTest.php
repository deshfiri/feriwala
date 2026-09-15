<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Order\Enums\OrderNotificationStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderStatusChange;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use App\Support\StatusHistory\StatusChange;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Order status history (P6-6, §18.3).
 *
 * Every status change stores previous status, new status, who, when, why, an
 * internal note, a user-visible note and the notification decision — and what
 * moved it — written with the move and never edited or removed afterwards.
 */

beforeEach(function () {
    $this->order = Order::factory()->create();
});

it('records everything §18.3 lists, and what moved it, with the move itself', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $staff = testPlatformStaff(PlatformRole::OrderManager);
    $at = now()->toImmutable()->startOfSecond();

    // A hold says when and why on the order itself; the database refuses one that does not.
    $this->order->forceFill(['held_at' => $at, 'hold_reason' => 'Stock reservation could not be committed']);

    $entry = $this->order->moveTo(OrderStatus::OnHold, new StatusChange(
        actorId: $staff->id,
        reason: 'Stock reservation could not be committed',
        internalNote: 'Dhaka warehouse count was wrong',
        publicNote: 'We are checking stock for your order.',
        at: $at,
    ), OrderStatusChangeSource::Staff, OrderNotificationStatus::Queued);

    $entry->refresh();

    expect($this->order->refresh()->status)->toBe(OrderStatus::OnHold)
        ->and($entry->previous_status)->toBe(OrderStatus::PaymentPending)
        ->and($entry->new_status)->toBe(OrderStatus::OnHold)
        ->and($entry->changed_by)->toBe($staff->id)
        ->and($entry->changed_at->equalTo($at))->toBeTrue()
        ->and($entry->reason)->toBe('Stock reservation could not be committed')
        ->and($entry->internal_note)->toBe('Dhaka warehouse count was wrong')
        ->and($entry->public_note)->toBe('We are checking stock for your order.')
        ->and($entry->source)->toBe(OrderStatusChangeSource::Staff)
        ->and($entry->notification_status)->toBe(OrderNotificationStatus::Queued);
});

it('records the status an order was placed in, which has no previous one', function () {
    $entry = $this->order->recordPlacement(
        new StatusChange(actorId: $this->order->placed_by, reason: 'Order placed; awaiting payment'),
        OrderStatusChangeSource::Checkout,
    );

    expect($entry->previous_status)->toBeNull()
        ->and($entry->new_status)->toBe(OrderStatus::PaymentPending)
        ->and($entry->changed_by)->toBe($this->order->placed_by)
        ->and($entry->notification_status)->toBe(OrderNotificationStatus::NotRequired);
});

it('keeps the history in the order the changes were made', function () {
    $this->order->recordPlacement(StatusChange::bySystem('Placed'), OrderStatusChangeSource::Checkout);
    $this->order->moveTo(OrderStatus::Paid, StatusChange::bySystem('Payment verified'), OrderStatusChangeSource::PaymentGateway);

    expect($this->order->statusHistory->map(fn (OrderStatusChange $change) => $change->new_status)->all())
        ->toBe([OrderStatus::PaymentPending, OrderStatus::Paid]);
});

it('refuses a move the map does not allow, and records nothing', function () {
    expect(fn () => $this->order->moveTo(OrderStatus::Delivered, StatusChange::bySystem(), OrderStatusChangeSource::System))
        ->toThrow(IllegalStateTransition::class);

    expect($this->order->refresh()->status)->toBe(OrderStatus::PaymentPending)
        ->and(OrderStatusChange::query()->count())->toBe(0);
});

it('refuses to edit or remove a recorded change, through Eloquent or in the database', function () {
    $this->order->forceFill(['cancelled_at' => now(), 'cancellation_reason' => 'Payment window ran out']);

    $entry = $this->order->moveTo(OrderStatus::Cancelled, new StatusChange(reason: 'Payment window ran out'), OrderStatusChangeSource::Scheduler);

    expect(fn () => $entry->forceFill(['reason' => 'Rewritten'])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('order_status_history')->where('id', $entry->id)->update(['reason' => 'Rewritten'])))
        ->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('order_status_history')->where('id', $entry->id)->delete()))
        ->toThrow(QueryException::class, 'append-only');

    expect(OrderStatusChange::query()->sole()->reason)->toBe('Payment window ran out');
});

it('refuses a source, a notification status or a status it does not know', function (array $row, string $constraint) {
    expect(fn () => DB::table('order_status_history')->insert([
        'order_id' => $this->order->id,
        'previous_status' => 'payment_pending',
        'new_status' => 'paid',
        'changed_at' => now(),
        'source' => 'payment_gateway',
        'notification_status' => 'not_required',
        ...$row,
    ]))->toThrow(QueryException::class, $constraint);
})->with([
    'an unknown source' => [['source' => 'rumour'], 'order_status_history_source_known'],
    'an unknown notification status' => [['notification_status' => 'delivered'], 'order_status_history_notification_status_known'],
    'an unknown status' => [['new_status' => 'teleported'], 'order_status_history_new_status_foreign'],
    'a change to the same status' => [['new_status' => 'payment_pending'], 'order_status_history_is_a_change'],
]);

it('keeps the internal note apart from the note the account may read', function () {
    $this->order->forceFill(['cancelled_at' => now(), 'cancellation_reason' => 'Payment failed']);

    $entry = $this->order->moveTo(OrderStatus::Cancelled, new StatusChange(
        reason: 'Payment failed',
        internalNote: 'Gateway returned a risk decline',
        publicNote: 'Your payment did not go through, so the order was cancelled.',
    ), OrderStatusChangeSource::PaymentGateway);

    expect($entry->public_note)->not->toContain('risk')
        ->and($entry->internal_note)->toContain('risk');
});
