<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The guarded order transition map (P6-5).
 *
 * Seeded from `App\Domain\Order\Enums\OrderStatus::transitionsTo()`, which is the
 * authority, and written out here so this migration keeps saying what it said;
 * `OrderStatusTransitionTest` fails if the two ever disagree.
 *
 * Kept in the database as well as in code so the database can hold an order to
 * it: `feriwala_order_transition_is_allowed()` is the guard the `orders` table
 * attaches (P6-1), so a status written by a migration, a console command or a
 * psql prompt is refused unless the map allows the move — exactly as
 * `transitionTo()` refuses it in the application.
 *
 * A system transition is fixed: deleting or rewriting one is refused, so the
 * database map cannot drift from the enum by hand.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> */
    private const SYSTEM_TRANSITIONS = [
        'draft' => ['new', 'pending_confirmation', 'payment_pending', 'cancelled'],
        'new' => ['pending_confirmation', 'customer_verification_pending', 'confirmed', 'payment_pending', 'on_hold', 'cancelled'],
        'pending_confirmation' => ['customer_verification_pending', 'confirmed', 'on_hold', 'cancelled'],
        'customer_verification_pending' => ['confirmed', 'on_hold', 'cancelled'],
        'confirmed' => ['payment_pending', 'processing', 'stock_reserved', 'ready_for_fulfillment', 'on_hold', 'cancelled'],
        'payment_pending' => ['paid', 'on_hold', 'cancelled'],
        'paid' => ['processing', 'stock_reserved', 'ready_for_fulfillment', 'on_hold', 'refund_pending'],
        'processing' => ['stock_reserved', 'ready_for_fulfillment', 'on_hold', 'refund_pending', 'cancelled'],
        'stock_reserved' => ['processing', 'ready_for_fulfillment', 'on_hold', 'refund_pending', 'cancelled'],
        'ready_for_fulfillment' => ['picking', 'on_hold', 'refund_pending', 'cancelled'],
        'picking' => ['packing', 'ready_for_fulfillment', 'on_hold'],
        'packing' => ['ready_for_pickup', 'picking', 'on_hold'],
        'ready_for_pickup' => ['courier_assigned', 'on_hold'],
        'courier_assigned' => ['shipped', 'ready_for_pickup', 'on_hold'],
        'shipped' => ['in_transit', 'delivered', 'delivery_failed'],
        'in_transit' => ['delivered', 'delivery_failed'],
        'delivered' => ['completed', 'return_requested'],
        'completed' => ['return_requested'],
        'delivery_failed' => ['in_transit', 'returning', 'on_hold', 'cancelled'],
        'on_hold' => [
            'new', 'pending_confirmation', 'confirmed', 'payment_pending', 'paid',
            'processing', 'stock_reserved', 'ready_for_fulfillment', 'refund_pending', 'cancelled',
        ],
        'cancelled' => [],
        'return_requested' => ['return_approved', 'completed', 'on_hold'],
        'return_approved' => ['returning', 'on_hold'],
        'returning' => ['returned'],
        'returned' => ['refund_pending', 'completed'],
        'refund_pending' => ['partially_refunded', 'refunded'],
        'partially_refunded' => ['refunded'],
        'refunded' => [],
    ];

    public function up(): void
    {
        Schema::create('order_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('from_status', 40);
            $table->string('to_status', 40);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->foreign('from_status')->references('code')->on('order_statuses')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreign('to_status')->references('code')->on('order_statuses')->restrictOnDelete()->cascadeOnUpdate();
            $table->unique(['from_status', 'to_status']);
        });

        $now = now();
        $rows = [];

        foreach (self::SYSTEM_TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                $rows[] = [
                    'from_status' => $from,
                    'to_status' => $to,
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('order_status_transitions')->insert($rows);

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_status_transitions
                ADD CONSTRAINT order_status_transitions_not_to_itself CHECK (from_status <> to_status);

            CREATE OR REPLACE FUNCTION feriwala_order_system_transition_is_fixed() RETURNS trigger AS $$
            BEGIN
                IF OLD.is_system THEN
                    RAISE EXCEPTION 'order transition % -> % is a system transition and is fixed', OLD.from_status, OLD.to_status
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_status_transitions_system_is_fixed
                BEFORE UPDATE OR DELETE ON order_status_transitions
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_system_transition_is_fixed();

            -- The guard an orders table attaches: a status may only change along the map.
            CREATE OR REPLACE FUNCTION feriwala_order_transition_is_allowed() RETURNS trigger AS $$
            BEGIN
                IF NEW.status IS DISTINCT FROM OLD.status AND NOT EXISTS (
                    SELECT 1 FROM order_status_transitions
                    WHERE from_status = OLD.status AND to_status = NEW.status
                ) THEN
                    RAISE EXCEPTION 'order % cannot move from % to %', OLD.reference, OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_transitions');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS feriwala_order_system_transition_is_fixed();
            DROP FUNCTION IF EXISTS feriwala_order_transition_is_allowed();
        SQL);
    }
};
