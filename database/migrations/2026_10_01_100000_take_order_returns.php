<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Goods coming back, and what is done with them (§18.2, §19.1, §26.3,
 * contract §6.3, P6-12).
 *
 * A return is a **request**, never an executed action: asking for one moves no
 * stock and no money. Approval, receipt, what is done with the goods and any
 * refund each happen afterwards, under the people who own those decisions.
 *
 * The database holds the invariants rather than trusting the code above it:
 *
 *   - a return belongs to one order, and to that order's business account and
 *     website — never another partner's;
 *   - every quantity is positive, and **no more comes back than went out**:
 *     across every live return of one line, the quantities asked for can never
 *     exceed what that line sold;
 *   - what is approved never exceeds what was asked for, and what is received
 *     never exceeds what was approved;
 *   - goods are restored to stock **once**: the movement that did it is named
 *     on the line, and one movement answers to one line;
 *   - a refund figure is never negative, is in the currency the order was paid
 *     in, and one refund request belongs to one return;
 *   - a decision carries its reason;
 *   - nothing here is ever deleted, and what a return *is* — its order, its
 *     shop, its reference — cannot be rewritten afterwards.
 *
 * The status history is the shared one (P0-16), append-only by the same guard
 * every other history uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_returns', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();

            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();
            $table->foreignId('website_id')->nullable()->constrained('websites')->restrictOnDelete();

            $table->string('source', 16);
            $table->string('status', 16);
            $table->string('reason', 32);
            $table->text('customer_note')->nullable();

            // What the customer sent with the request — references, not files.
            $table->jsonb('evidence')->nullable();

            $table->text('decision_note')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('refund_state', 16)->default('not_required');
            $table->foreignId('refund_request_id')->nullable()->constrained('refund_requests')->restrictOnDelete();
            $table->bigInteger('refund_amount_minor')->nullable();
            $table->char('currency_code', 3)->default('BDT');
            $table->text('refund_note')->nullable();

            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->index(['order_id', 'id']);
            $table->index(['business_account_id', 'status']);
            $table->index(['website_id', 'requested_at']);
        });

        Schema::create('order_return_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_return_id')->constrained('order_returns')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();

            $table->unsignedInteger('quantity');
            $table->unsignedInteger('approved_quantity')->nullable();
            $table->unsignedInteger('received_quantity')->default(0);

            $table->string('disposition', 16)->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamp('restored_at')->nullable();

            $table->bigInteger('refund_amount_minor')->nullable();
            $table->char('currency_code', 3)->default('BDT');

            $table->timestamps();

            $table->unique(['order_return_id', 'order_item_id']);
        });

        Schema::create('order_return_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_return_id')->constrained('order_returns')->restrictOnDelete();

            $table->string('previous_status', 16)->nullable();
            $table->string('new_status', 16);
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->string('source', 16);

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();

            $table->index(['order_return_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_returns
                ADD CONSTRAINT order_returns_status_known CHECK (
                    status IN ('requested', 'approved', 'rejected', 'received', 'refunded', 'cancelled')
                ),
                ADD CONSTRAINT order_returns_source_known CHECK (
                    source IN ('storefront', 'account', 'staff')
                ),
                ADD CONSTRAINT order_returns_refund_state_known CHECK (
                    refund_state IN ('not_required', 'pending', 'completed', 'failed', 'manual_review')
                ),
                ADD CONSTRAINT order_returns_reason_present CHECK (length(btrim(reason)) > 0),
                -- Taking goods back, or refusing to, is a decision somebody has to account for.
                ADD CONSTRAINT order_returns_decision_has_a_reason CHECK (
                    status NOT IN ('approved', 'rejected') OR (decided_at IS NOT NULL AND length(btrim(coalesce(decision_note, ''))) > 0)
                ),
                ADD CONSTRAINT order_returns_received_is_dated CHECK (
                    status NOT IN ('received', 'refunded') OR received_at IS NOT NULL
                ),
                ADD CONSTRAINT order_returns_refund_is_not_negative CHECK (
                    refund_amount_minor IS NULL OR refund_amount_minor >= 0
                ),
                ADD CONSTRAINT order_returns_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$');

            -- One refund request answers to one return.
            CREATE UNIQUE INDEX order_returns_one_refund_request
                ON order_returns (refund_request_id) WHERE refund_request_id IS NOT NULL;

            -- The same key on one order is the same return, never a second.
            CREATE UNIQUE INDEX order_returns_one_per_idempotency_key
                ON order_returns (order_id, idempotency_key) WHERE idempotency_key IS NOT NULL;

            ALTER TABLE order_return_items
                ADD CONSTRAINT order_return_items_quantity_is_positive CHECK (quantity > 0),
                ADD CONSTRAINT order_return_items_approved_within_requested CHECK (
                    approved_quantity IS NULL OR approved_quantity <= quantity
                ),
                ADD CONSTRAINT order_return_items_received_within_approved CHECK (
                    received_quantity <= coalesce(approved_quantity, 0)
                ),
                ADD CONSTRAINT order_return_items_disposition_known CHECK (
                    disposition IS NULL OR disposition IN ('restock', 'damaged', 'quarantine')
                ),
                -- A disposition is what was done with goods that arrived.
                ADD CONSTRAINT order_return_items_disposition_needs_goods CHECK (
                    disposition IS NULL OR received_quantity > 0
                ),
                -- Restoring is one movement, named here, and the warehouse it went to.
                ADD CONSTRAINT order_return_items_restoration_is_complete CHECK (
                    (stock_movement_id IS NULL AND restored_at IS NULL)
                    OR (stock_movement_id IS NOT NULL AND restored_at IS NOT NULL
                        AND warehouse_id IS NOT NULL AND disposition IS NOT NULL)
                ),
                ADD CONSTRAINT order_return_items_refund_is_not_negative CHECK (
                    refund_amount_minor IS NULL OR refund_amount_minor >= 0
                );

            -- One movement restored one line, and no line was restored twice.
            CREATE UNIQUE INDEX order_return_items_one_restoration
                ON order_return_items (stock_movement_id) WHERE stock_movement_id IS NOT NULL;

            ALTER TABLE order_return_status_history
                ADD CONSTRAINT order_return_status_history_source_known CHECK (
                    source IN ('storefront', 'account', 'staff', 'system', 'scheduler', 'payment_gateway')
                ),
                ADD CONSTRAINT order_return_status_history_is_a_change CHECK (
                    previous_status IS DISTINCT FROM new_status
                );

            CREATE TRIGGER order_return_status_history_no_update
                BEFORE UPDATE ON order_return_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER order_return_status_history_no_delete
                BEFORE DELETE ON order_return_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            /*
             * A return belongs to its order's account and its order's shop. A
             * partner reading another partner's return is a query mistake; a
             * return *written* against another partner's order is refused here.
             */
            CREATE OR REPLACE FUNCTION feriwala_return_belongs_to_its_order() RETURNS trigger AS $$
            DECLARE
                owning_account bigint;
                owning_website bigint;
                order_currency char(3);
            BEGIN
                SELECT business_account_id, website_id, currency_code INTO owning_account, owning_website, order_currency
                FROM orders WHERE id = NEW.order_id;

                -- Money given back is in the money that was taken.
                IF order_currency IS DISTINCT FROM NEW.currency_code THEN
                    RAISE EXCEPTION 'a return is in the currency of the order it is for'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF owning_account IS DISTINCT FROM NEW.business_account_id THEN
                    RAISE EXCEPTION 'a return belongs to the business account of the order it is for'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF owning_website IS DISTINCT FROM NEW.website_id THEN
                    RAISE EXCEPTION 'a return belongs to the website of the order it is for'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_returns_belong_to_their_order
                BEFORE INSERT ON order_returns
                FOR EACH ROW EXECUTE FUNCTION feriwala_return_belongs_to_its_order();

            /*
             * No more comes back than went out. Counted across every return of
             * the line that is still alive — a rejected or cancelled one has
             * given its claim up — and against the line's own order, so a line
             * of somebody else's order cannot be smuggled onto a return.
             */
            CREATE OR REPLACE FUNCTION feriwala_return_is_within_what_was_sold() RETURNS trigger AS $$
            DECLARE
                sold integer;
                line_order bigint;
                return_order bigint;
                claimed integer;
            BEGIN
                SELECT quantity, order_id INTO sold, line_order FROM order_items WHERE id = NEW.order_item_id;
                SELECT order_id INTO return_order FROM order_returns WHERE id = NEW.order_return_id;

                IF line_order IS DISTINCT FROM return_order THEN
                    RAISE EXCEPTION 'a returned line must belong to the order the return is for'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                SELECT coalesce(sum(coalesce(items.approved_quantity, items.quantity)), 0) INTO claimed
                FROM order_return_items items
                JOIN order_returns returns ON returns.id = items.order_return_id
                WHERE items.order_item_id = NEW.order_item_id
                  AND items.id IS DISTINCT FROM NEW.id
                  AND returns.status NOT IN ('rejected', 'cancelled');

                IF claimed + coalesce(NEW.approved_quantity, NEW.quantity) > sold THEN
                    RAISE EXCEPTION 'more of this line would come back than was sold'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_return_items_within_what_was_sold
                BEFORE INSERT OR UPDATE OF quantity, approved_quantity ON order_return_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_return_is_within_what_was_sold();

            /*
             * What a return is cannot be rewritten: its order, its shop, when it
             * was asked for, what it is called. Its status and what has been
             * done with the goods move; its identity does not.
             */
            CREATE TRIGGER order_returns_locked_columns
                BEFORE UPDATE OF public_id, reference, order_id, business_account_id, website_id, source, requested_at
                ON order_returns
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'order_id', 'business_account_id', 'website_id', 'source', 'requested_at'
                );

            CREATE TRIGGER order_return_items_locked_columns
                BEFORE UPDATE OF public_id, order_return_id, order_item_id, quantity ON order_return_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'order_return_id', 'order_item_id', 'quantity'
                );

            CREATE OR REPLACE FUNCTION feriwala_returns_are_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is never deleted: a return that was asked for stays asked for', TG_TABLE_NAME
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_returns_no_delete
                BEFORE DELETE ON order_returns
                FOR EACH ROW EXECUTE FUNCTION feriwala_returns_are_never_deleted();

            CREATE TRIGGER order_return_items_no_delete
                BEFORE DELETE ON order_return_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_returns_are_never_deleted();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS order_return_items_no_delete ON order_return_items;
            DROP TRIGGER IF EXISTS order_returns_no_delete ON order_returns;
            DROP FUNCTION IF EXISTS feriwala_returns_are_never_deleted();
            DROP TRIGGER IF EXISTS order_return_items_locked_columns ON order_return_items;
            DROP TRIGGER IF EXISTS order_returns_locked_columns ON order_returns;
            DROP TRIGGER IF EXISTS order_return_items_within_what_was_sold ON order_return_items;
            DROP FUNCTION IF EXISTS feriwala_return_is_within_what_was_sold();
            DROP TRIGGER IF EXISTS order_returns_belong_to_their_order ON order_returns;
            DROP FUNCTION IF EXISTS feriwala_return_belongs_to_its_order();
        SQL);

        Schema::dropIfExists('order_return_status_history');
        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
    }
};
