<?php

use App\Domain\Order\Enums\OrderCourierStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A real state machine for `orders.courier_status` (§18, §21, P6.C).
 *
 * Same shape as the two lifecycle migrations before this one, for
 * {@see OrderCourierStatus}.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: bool}> code, label, terminal */
    private const STATUSES = [
        ['unassigned', 'No courier assigned', false],
        ['assigned', 'Courier assigned', false],
        ['pickup_requested', 'Pickup requested', false],
        ['picked_up', 'Picked up', false],
        ['in_transit', 'In transit', false],
        ['delivered', 'Delivered', true],
        ['failed_delivery', 'Failed delivery', false],
        ['returned_to_origin', 'Returned to origin', true],
        ['cancelled', 'Cancelled', true],
    ];

    /** @var array<string, array<int, string>> */
    private const TRANSITIONS = [
        'unassigned' => ['assigned', 'cancelled'],
        'assigned' => ['pickup_requested', 'cancelled'],
        'pickup_requested' => ['picked_up', 'cancelled'],
        'picked_up' => ['in_transit', 'failed_delivery'],
        'in_transit' => ['delivered', 'failed_delivery'],
        'delivered' => [],
        'failed_delivery' => ['returned_to_origin', 'assigned'],
        'returned_to_origin' => [],
        'cancelled' => [],
    ];

    public function up(): void
    {
        $this->createStatusLookup('order_courier_statuses', self::STATUSES);
        $this->createStatusTransitions('order_courier_status_transitions', 'order_courier_statuses', self::TRANSITIONS);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER order_courier_statuses_system_is_fixed
                BEFORE UPDATE OR DELETE ON order_courier_statuses
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_lookup_system_row_is_fixed();

            CREATE TRIGGER order_courier_status_transitions_system_is_fixed
                BEFORE UPDATE OR DELETE ON order_courier_status_transitions
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_transition_system_row_is_fixed();

            CREATE OR REPLACE FUNCTION feriwala_order_courier_transition_is_allowed() RETURNS trigger AS $$
            BEGIN
                IF NEW.courier_status IS DISTINCT FROM OLD.courier_status AND NOT EXISTS (
                    SELECT 1 FROM order_courier_status_transitions
                    WHERE from_status = OLD.courier_status AND to_status = NEW.courier_status
                ) THEN
                    RAISE EXCEPTION 'order % cannot move courier status from % to %', OLD.reference, OLD.courier_status, NEW.courier_status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            ALTER TABLE orders DROP CONSTRAINT orders_courier_status_known;
            ALTER TABLE orders ADD CONSTRAINT orders_courier_status_fkey
                FOREIGN KEY (courier_status) REFERENCES order_courier_statuses (code) ON UPDATE CASCADE;

            CREATE TRIGGER orders_courier_status_follows_transition_map
                BEFORE UPDATE OF courier_status ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_courier_transition_is_allowed();
        SQL);

        Schema::create('order_courier_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();

            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40);
            $table->foreign('previous_status')->references('code')->on('order_courier_statuses')->restrictOnDelete();
            $table->foreign('new_status')->references('code')->on('order_courier_statuses')->restrictOnDelete();

            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->string('source', 32);

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();

            $table->index(['order_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_courier_status_history
                ADD CONSTRAINT order_courier_status_history_source_known CHECK (
                    source IN ('checkout', 'payment_gateway', 'scheduler', 'account', 'staff', 'system', 'storefront')
                ),
                ADD CONSTRAINT order_courier_status_history_is_a_change CHECK (previous_status IS DISTINCT FROM new_status);

            CREATE TRIGGER order_courier_status_history_no_update
                BEFORE UPDATE ON order_courier_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER order_courier_status_history_no_delete
                BEFORE DELETE ON order_courier_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_courier_status_history');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS orders_courier_status_follows_transition_map ON orders;
            ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_courier_status_fkey;
            ALTER TABLE orders ADD CONSTRAINT orders_courier_status_known CHECK (courier_status IN ('unassigned'));
            DROP FUNCTION IF EXISTS feriwala_order_courier_transition_is_allowed();
        SQL);

        Schema::dropIfExists('order_courier_status_transitions');
        Schema::dropIfExists('order_courier_statuses');
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: bool}>  $statuses
     */
    private function createStatusLookup(string $table, array $statuses): void
    {
        Schema::create($table, function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->string('code', 40)->unique();
            $blueprint->string('label', 80);
            $blueprint->boolean('is_system')->default(false);
            $blueprint->boolean('is_terminal')->default(false);
            $blueprint->unsignedSmallInteger('sort_order');
            $blueprint->timestamps();
        });

        $now = now();

        DB::table($table)->insert(array_map(
            fn (array $status, int $index) => [
                'code' => $status[0],
                'label' => $status[1],
                'is_system' => true,
                'is_terminal' => $status[2],
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $statuses,
            array_keys($statuses),
        ));
    }

    /**
     * @param  array<string, array<int, string>>  $transitions
     */
    private function createStatusTransitions(string $table, string $referencesTable, array $transitions): void
    {
        Schema::create($table, function (Blueprint $blueprint) use ($referencesTable) {
            $blueprint->id();
            $blueprint->string('from_status', 40);
            $blueprint->string('to_status', 40);
            $blueprint->boolean('is_system')->default(false);
            $blueprint->timestamps();

            $blueprint->foreign('from_status')->references('code')->on($referencesTable)->restrictOnDelete()->cascadeOnUpdate();
            $blueprint->foreign('to_status')->references('code')->on($referencesTable)->restrictOnDelete()->cascadeOnUpdate();
            $blueprint->unique(['from_status', 'to_status']);
        });

        $now = now();
        $rows = [];

        foreach ($transitions as $from => $targets) {
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

        DB::table($table)->insert($rows);

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_not_to_itself CHECK (from_status <> to_status)");
    }
};
