<?php

use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The guarded order fulfilment-status transition map, mirroring
 * OrderStatusTransitionTest for App\Domain\Order\Enums\OrderFulfillmentStatus
 * and order_fulfillment_status_transitions.
 */

it('holds exactly the moves the enum allows, and no others', function () {
    $table = DB::table('order_fulfillment_status_transitions')
        ->orderBy('from_status')
        ->orderBy('to_status')
        ->get()
        ->map(fn (object $row) => $row->from_status.' -> '.$row->to_status)
        ->all();

    $enum = [];

    foreach (OrderFulfillmentStatus::cases() as $from) {
        foreach ($from->transitionsTo() as $to) {
            $enum[] = $from->value.' -> '.$to->value;
        }
    }

    sort($enum);

    expect($table)->toBe($enum);
});

it('marks every seeded move a system transition', function () {
    expect(DB::table('order_fulfillment_status_transitions')->where('is_system', false)->count())->toBe(0);
});

it('refuses to remove or rewrite a system transition', function () {
    expect(fn () => DB::table('order_fulfillment_status_transitions')->where('from_status', 'processing')->where('to_status', 'picking')->delete())
        ->toThrow(QueryException::class, 'system transition');
});

it('refuses a move to an unknown status, a move to itself, and the same move twice', function (array $row) {
    expect(fn () => DB::table('order_fulfillment_status_transitions')->insert([...$row, 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
})->with([
    'an unknown status' => [['from_status' => 'processing', 'to_status' => 'teleported']],
    'a move to itself' => [['from_status' => 'processing', 'to_status' => 'processing']],
    'the same move twice' => [['from_status' => 'processing', 'to_status' => 'picking']],
]);

it('refuses orders.fulfillment_status moving along a route the map does not have', function () {
    $order = Order::factory()->create(['fulfillment_status' => OrderFulfillmentStatus::PendingReview]);

    expect(fn () => DB::table('orders')->where('id', $order->id)->update(['fulfillment_status' => 'fulfilled']))
        ->toThrow(QueryException::class, 'cannot move fulfilment status');
});
