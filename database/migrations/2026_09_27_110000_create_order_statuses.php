<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order statuses, seeded with every system status §18.2 names (P6-3).
 *
 * The rows mirror `App\Domain\Order\Enums\OrderStatus`, which is the authority;
 * the list is written out here rather than read from the enum so this migration
 * says the same thing in a year as it does today. `OrderStatusTest` fails if the
 * two ever disagree.
 *
 * **A system status is not the administrator's to remove or rename.** An order
 * holds its status by code, the transition map and the history refer to it, and
 * the storefront contract publishes it — so deleting one, or changing its code
 * or whether it is a system or terminal status, is refused by trigger. The
 * admin-defined custom statuses of §18.2 (P6-4) will be the rows that are not.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: bool}> code, label, terminal */
    private const SYSTEM_STATUSES = [
        ['draft', 'Draft', false],
        ['new', 'New', false],
        ['pending_confirmation', 'Pending confirmation', false],
        ['customer_verification_pending', 'Customer verification pending', false],
        ['confirmed', 'Confirmed', false],
        ['payment_pending', 'Payment pending', false],
        ['paid', 'Paid', false],
        ['processing', 'Processing', false],
        ['stock_reserved', 'Stock reserved', false],
        ['ready_for_fulfillment', 'Ready for fulfillment', false],
        ['picking', 'Picking', false],
        ['packing', 'Packing', false],
        ['ready_for_pickup', 'Ready for pickup', false],
        ['courier_assigned', 'Courier assigned', false],
        ['shipped', 'Shipped', false],
        ['in_transit', 'In transit', false],
        ['delivered', 'Delivered', false],
        ['completed', 'Completed', false],
        ['delivery_failed', 'Delivery failed', false],
        ['on_hold', 'On hold', false],
        ['cancelled', 'Cancelled', true],
        ['return_requested', 'Return requested', false],
        ['return_approved', 'Return approved', false],
        ['returning', 'Returning', false],
        ['returned', 'Returned', false],
        ['refund_pending', 'Refund pending', false],
        ['partially_refunded', 'Partially refunded', false],
        ['refunded', 'Refunded', true],
    ];

    public function up(): void
    {
        Schema::create('order_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('label', 80);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
        });

        $now = now();

        DB::table('order_statuses')->insert(array_map(
            fn (array $status, int $index) => [
                'code' => $status[0],
                'label' => $status[1],
                'is_system' => true,
                'is_terminal' => $status[2],
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            self::SYSTEM_STATUSES,
            array_keys(self::SYSTEM_STATUSES),
        ));

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_order_system_status_is_fixed() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND OLD.is_system THEN
                    RAISE EXCEPTION 'order status % is a system status and cannot be deleted', OLD.code
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.is_system AND (
                    NEW.code IS DISTINCT FROM OLD.code
                    OR NEW.is_system IS DISTINCT FROM OLD.is_system
                    OR NEW.is_terminal IS DISTINCT FROM OLD.is_terminal
                ) THEN
                    RAISE EXCEPTION 'order status % is a system status: its code and kind are fixed', OLD.code
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_statuses_system_is_fixed
                BEFORE UPDATE OR DELETE ON order_statuses
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_system_status_is_fixed();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_statuses');

        DB::unprepared('DROP FUNCTION IF EXISTS feriwala_order_system_status_is_fixed();');
    }
};
