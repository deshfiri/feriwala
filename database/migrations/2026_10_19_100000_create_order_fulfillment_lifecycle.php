<?php

use App\Domain\Order\Enums\OrderFulfillmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A real state machine for `orders.fulfillment_status` (§18, §20, P6.B).
 *
 * `fulfillment_status` existed since the orders table was created, fixed at
 * its one placeholder value (`unfulfilled`) by a plain CHECK. This gives it
 * the same guarded-transition-map treatment `orders.status` already has
 * (`order_statuses`/`order_status_transitions`/`feriwala_order_transition_is_allowed()`):
 * a lookup table of known codes, a transitions table seeded from
 * {@see OrderFulfillmentStatus::transitionsTo()}, and
 * a trigger refusing any move the map does not have — `OrderFulfillmentStatusTransitionTest`
 * fails if the two ever disagree.
 *
 * The two guard functions below (`feriwala_status_lookup_system_row_is_fixed()`,
 * `feriwala_status_transition_system_row_is_fixed()`) are written once here and
 * reused, unmodified, by the matching delivery and courier migrations that
 * follow — each operates generically on `(code, is_system, is_terminal)` or
 * `(from_status, to_status, is_system)` columns via `TG_TABLE_NAME`, so one
 * function serves every status-lookup/transition table in the schema.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: bool}> code, label, terminal */
    private const STATUSES = [
        ['pending_review', 'Pending review', false],
        ['source_allocation_pending', 'Source allocation pending', false],
        ['supplier_confirmation_pending', 'Supplier confirmation pending', false],
        ['processing', 'Processing', false],
        ['picking', 'Picking', false],
        ['packing', 'Packing', false],
        ['ready_for_dispatch', 'Ready for dispatch', false],
        ['partially_fulfilled', 'Partially fulfilled', false],
        ['fulfilled', 'Fulfilled', true],
        ['on_hold', 'On hold', false],
        ['cancelled', 'Cancelled', true],
    ];

    /** @var array<string, array<int, string>> */
    private const TRANSITIONS = [
        'pending_review' => ['source_allocation_pending', 'on_hold', 'cancelled'],
        'source_allocation_pending' => ['supplier_confirmation_pending', 'processing', 'on_hold', 'cancelled'],
        'supplier_confirmation_pending' => ['processing', 'source_allocation_pending', 'on_hold', 'cancelled'],
        'processing' => ['picking', 'partially_fulfilled', 'on_hold', 'cancelled'],
        'picking' => ['packing', 'on_hold'],
        'packing' => ['ready_for_dispatch', 'on_hold'],
        'ready_for_dispatch' => ['fulfilled', 'partially_fulfilled', 'on_hold'],
        'partially_fulfilled' => ['processing', 'ready_for_dispatch', 'fulfilled', 'on_hold', 'cancelled'],
        'fulfilled' => [],
        'on_hold' => [
            'source_allocation_pending', 'supplier_confirmation_pending', 'processing',
            'picking', 'packing', 'ready_for_dispatch', 'partially_fulfilled', 'cancelled',
        ],
        'cancelled' => [],
    ];

    public function up(): void
    {
        $this->createStatusLookup('order_fulfillment_statuses', self::STATUSES);
        $this->createStatusTransitions('order_fulfillment_status_transitions', 'order_fulfillment_statuses', self::TRANSITIONS);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_status_lookup_system_row_is_fixed() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND OLD.is_system THEN
                    RAISE EXCEPTION '% % is a system row and cannot be deleted', TG_TABLE_NAME, OLD.code
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.is_system AND (
                    NEW.code IS DISTINCT FROM OLD.code
                    OR NEW.is_system IS DISTINCT FROM OLD.is_system
                    OR NEW.is_terminal IS DISTINCT FROM OLD.is_terminal
                ) THEN
                    RAISE EXCEPTION '% % is a system row: its code and kind are fixed', TG_TABLE_NAME, OLD.code
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION feriwala_status_transition_system_row_is_fixed() RETURNS trigger AS $$
            BEGIN
                IF OLD.is_system THEN
                    RAISE EXCEPTION '% transition % -> % is a system transition and is fixed', TG_TABLE_NAME, OLD.from_status, OLD.to_status
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_fulfillment_statuses_system_is_fixed
                BEFORE UPDATE OR DELETE ON order_fulfillment_statuses
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_lookup_system_row_is_fixed();

            CREATE TRIGGER order_fulfillment_status_transitions_system_is_fixed
                BEFORE UPDATE OR DELETE ON order_fulfillment_status_transitions
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_transition_system_row_is_fixed();

            CREATE OR REPLACE FUNCTION feriwala_order_fulfillment_transition_is_allowed() RETURNS trigger AS $$
            BEGIN
                IF NEW.fulfillment_status IS DISTINCT FROM OLD.fulfillment_status AND NOT EXISTS (
                    SELECT 1 FROM order_fulfillment_status_transitions
                    WHERE from_status = OLD.fulfillment_status AND to_status = NEW.fulfillment_status
                ) THEN
                    RAISE EXCEPTION 'order % cannot move fulfilment status from % to %', OLD.reference, OLD.fulfillment_status, NEW.fulfillment_status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            ALTER TABLE orders DROP CONSTRAINT orders_fulfillment_status_known;
            ALTER TABLE orders ALTER COLUMN fulfillment_status SET DEFAULT 'pending_review';
            UPDATE orders SET fulfillment_status = 'pending_review' WHERE fulfillment_status = 'unfulfilled';
            ALTER TABLE orders ADD CONSTRAINT orders_fulfillment_status_fkey
                FOREIGN KEY (fulfillment_status) REFERENCES order_fulfillment_statuses (code) ON UPDATE CASCADE;

            CREATE TRIGGER orders_fulfillment_status_follows_transition_map
                BEFORE UPDATE OF fulfillment_status ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_fulfillment_transition_is_allowed();
        SQL);

        Schema::create('order_fulfillment_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();

            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40);
            $table->foreign('previous_status')->references('code')->on('order_fulfillment_statuses')->restrictOnDelete();
            $table->foreign('new_status')->references('code')->on('order_fulfillment_statuses')->restrictOnDelete();

            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->string('source', 32);

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();

            $table->index(['order_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_fulfillment_status_history
                ADD CONSTRAINT order_fulfillment_status_history_source_known CHECK (
                    source IN ('checkout', 'payment_gateway', 'scheduler', 'account', 'staff', 'system', 'storefront')
                ),
                ADD CONSTRAINT order_fulfillment_status_history_is_a_change CHECK (previous_status IS DISTINCT FROM new_status);

            CREATE TRIGGER order_fulfillment_status_history_no_update
                BEFORE UPDATE ON order_fulfillment_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER order_fulfillment_status_history_no_delete
                BEFORE DELETE ON order_fulfillment_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fulfillment_status_history');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS orders_fulfillment_status_follows_transition_map ON orders;
            ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_fulfillment_status_fkey;
            UPDATE orders SET fulfillment_status = 'unfulfilled' WHERE fulfillment_status = 'pending_review';
            ALTER TABLE orders ALTER COLUMN fulfillment_status SET DEFAULT 'unfulfilled';
            ALTER TABLE orders ADD CONSTRAINT orders_fulfillment_status_known CHECK (fulfillment_status IN ('unfulfilled'));
            DROP FUNCTION IF EXISTS feriwala_order_fulfillment_transition_is_allowed();
        SQL);

        Schema::dropIfExists('order_fulfillment_status_transitions');
        Schema::dropIfExists('order_fulfillment_statuses');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS feriwala_status_lookup_system_row_is_fixed() CASCADE;
            DROP FUNCTION IF EXISTS feriwala_status_transition_system_row_is_fixed() CASCADE;
        SQL);
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
