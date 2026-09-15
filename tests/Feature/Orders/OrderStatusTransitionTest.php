<?php

use App\Domain\Order\Enums\OrderStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The guarded order transition map (P6-5).
 *
 * The enum is the authority; the table is seeded from it so the database can
 * hold an order to the same map. These tests fail the moment the two disagree,
 * and refuse a system transition being removed, rewritten or added to by hand.
 */

it('holds exactly the moves the enum allows, and no others', function () {
    $table = DB::table('order_status_transitions')
        ->orderBy('from_status')
        ->orderBy('to_status')
        ->get()
        ->map(fn (object $row) => $row->from_status.' -> '.$row->to_status)
        ->all();

    $enum = [];

    foreach (OrderStatus::cases() as $from) {
        foreach ($from->transitionsTo() as $to) {
            $enum[] = $from->value.' -> '.$to->value;
        }
    }

    sort($enum);

    expect($table)->toBe($enum);
});

it('marks every seeded move a system transition', function () {
    expect(DB::table('order_status_transitions')->where('is_system', false)->count())->toBe(0);
});

it('refuses to remove or rewrite a system transition', function () {
    expect(fn () => DB::table('order_status_transitions')->where('from_status', 'payment_pending')->where('to_status', 'paid')->delete())
        ->toThrow(QueryException::class, 'system transition');
});

it('refuses to point a system transition somewhere else', function () {
    expect(fn () => DB::table('order_status_transitions')->where('from_status', 'payment_pending')->where('to_status', 'paid')->update(['to_status' => 'refunded']))
        ->toThrow(QueryException::class, 'system transition');
});

it('refuses a move to an unknown status, a move to itself, and the same move twice', function (array $row) {
    expect(fn () => DB::table('order_status_transitions')->insert([...$row, 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
})->with([
    'an unknown status' => [['from_status' => 'paid', 'to_status' => 'teleported']],
    'a move to itself' => [['from_status' => 'paid', 'to_status' => 'paid']],
    'the same move twice' => [['from_status' => 'payment_pending', 'to_status' => 'paid']],
]);
