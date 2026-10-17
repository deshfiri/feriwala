<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The few order moves the strict Store Order workflow needs that the status map
 * did not already have (D34, Store Orders):
 *
 * - Processing (and the older stock-reserved / ready-for-fulfilment stops) →
 *   Packing, so "Start Packing" is one move rather than a walk through Picking;
 * - Ready for pickup (Packed) → Shipped (Dispatched), so "Dispatch Order" is one
 *   move rather than a walk through Courier assigned;
 * - Packing and Ready for pickup → Cancelled / Refund pending, so a packed order
 *   can still be called off by authorised staff with a reason, before it leaves.
 *
 * Additive: nothing is removed, and no order's status is rewritten.
 */
return new class extends Migration
{
    private const MOVES = [
        'processing' => ['packing'],
        'stock_reserved' => ['packing'],
        'ready_for_fulfillment' => ['packing'],
        'ready_for_pickup' => ['shipped', 'cancelled', 'refund_pending'],
        'packing' => ['cancelled', 'refund_pending'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::MOVES as $from => $targets) {
            foreach ($targets as $to) {
                DB::table('order_status_transitions')->insertOrIgnore([
                    'from_status' => $from,
                    'to_status' => $to,
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // System transitions are fixed by trigger and are never removed.
    }
};
