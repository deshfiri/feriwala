<?php

use App\Domain\Order\Enums\OrderStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Order statuses (P6-3, §18.2).
 *
 * Every system status §18.2 names, in one enum that is the authority and one
 * table seeded from it, and a system status is not something an administrator
 * can remove or rename.
 */

it('names every system status §18.2 lists', function () {
    expect(array_map(fn (OrderStatus $status) => $status->label(), OrderStatus::cases()))->toBe([
        'Draft', 'New', 'Pending confirmation', 'Customer verification pending', 'Confirmed',
        'Payment pending', 'Paid', 'Processing', 'Stock reserved', 'Ready for fulfillment',
        'Picking', 'Packing', 'Ready for pickup', 'Courier assigned', 'Shipped', 'In transit',
        'Delivered', 'Completed', 'Delivery failed', 'On hold', 'Cancelled', 'Return requested',
        'Return approved', 'Returning', 'Returned', 'Refund pending', 'Partially refunded', 'Refunded',
    ]);
});

it('seeds order_statuses with exactly the enum\'s system statuses', function () {
    $rows = DB::table('order_statuses')->orderBy('sort_order')->get();

    expect($rows->pluck('code')->all())->toBe(array_map(fn (OrderStatus $status) => $status->value, OrderStatus::cases()))
        ->and($rows->pluck('label')->all())->toBe(array_map(fn (OrderStatus $status) => $status->label(), OrderStatus::cases()))
        ->and($rows->every(fn (object $row) => $row->is_system === true))->toBeTrue()
        ->and($rows->mapWithKeys(fn (object $row) => [$row->code => $row->is_terminal])->all())
        ->toBe(collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $status) => [$status->value => $status->isTerminal()])->all());
});

it('refuses to delete a system status', function () {
    expect(fn () => DB::table('order_statuses')->where('code', 'paid')->delete())
        ->toThrow(QueryException::class, 'system status');
});

it('refuses to rename a system status or change what kind it is', function (array $change) {
    expect(fn () => DB::table('order_statuses')->where('code', 'paid')->update($change))
        ->toThrow(QueryException::class, 'system status');
})->with([
    'its code' => [['code' => 'settled']],
    'whether it is a system status' => [['is_system' => false]],
    'whether it is terminal' => [['is_terminal' => true]],
]);

it('leaves a status that is not a system status to be removed', function () {
    DB::table('order_statuses')->insert([
        'code' => 'awaiting_artwork', 'label' => 'Awaiting artwork', 'is_system' => false,
        'is_terminal' => false, 'sort_order' => 99, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('order_statuses')->where('code', 'awaiting_artwork')->delete();

    expect(DB::table('order_statuses')->where('code', 'awaiting_artwork')->exists())->toBeFalse();
});

describe('the transition map', function () {
    it('never moves a status to itself', function () {
        foreach (OrderStatus::cases() as $status) {
            expect($status->transitionsTo())->not->toContain($status);
        }
    });

    it('gives a terminal status no moves, and every other status at least one', function () {
        foreach (OrderStatus::cases() as $status) {
            expect($status->transitionsTo() === [])->toBe($status->isTerminal(), $status->value);
        }
    });

    it('reaches every status from a new order', function () {
        $reached = [OrderStatus::Draft];
        $queue = [OrderStatus::Draft];

        while ($queue !== []) {
            foreach (array_shift($queue)->transitionsTo() as $next) {
                if (! in_array($next, $reached, true)) {
                    $reached[] = $next;
                    $queue[] = $next;
                }
            }
        }

        expect(count($reached))->toBe(count(OrderStatus::cases()));
    });

    it('takes a settled order out only through fulfilment, a hold or a refund', function () {
        expect(OrderStatus::Paid->transitionsTo())->not->toContain(OrderStatus::Cancelled)
            ->and(OrderStatus::PaymentPending->transitionsTo())->toBe([OrderStatus::Paid, OrderStatus::OnHold, OrderStatus::Cancelled]);
    });
});
