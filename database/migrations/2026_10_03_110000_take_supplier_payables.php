<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What Feriwala owes a Supplier for one allocated order line (D25, P13-22).
 *
 * **A payable is a claim, never money.** It credits no wallet and creates
 * nothing withdrawable; settlement into a Supplier wallet is P13-23 and needs a
 * Supplier-owned wallet the current schema cannot express (`wallets` and
 * `ledger_entries` are owned by a `business_account_id`). What is here is the
 * whole record and the rules around it, so that batch only has to post money.
 *
 * Immutable in the way the ledger is:
 *
 *   - the identity and the figures (Supplier, order line, offer, price version,
 *     quantity, Supplier Rate, gross amount, currency, idempotency key) are
 *     locked by trigger the moment the row exists;
 *   - only the workflow columns move — status, the delivery and payment
 *     qualification timestamps, the hold and settlement references;
 *   - a return never edits the row: it appends a `supplier_payable_reversals`
 *     row, and the database refuses reversals that together exceed the original
 *     quantity or amount;
 *   - nothing is ever deleted, and every status change is one
 *     `supplier_payable_status_history` row.
 *
 * One payable per order line, by unique index — a retried order cannot accrue a
 * second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payables', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();

            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_offer_price_change_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');
            $table->bigInteger('supplier_rate_minor');
            $table->bigInteger('gross_amount_minor');
            $table->char('currency_code', 3);

            $table->string('status', 24);
            $table->string('triggering_event', 40);
            $table->string('idempotency_key', 160)->unique();

            // The two facts that together make a payable eligible for settlement.
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('payment_settled_at')->nullable();
            $table->timestamp('eligible_at')->nullable();

            $table->timestamp('held_at')->nullable();
            $table->text('hold_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Where the money went, once P13-23 moves it. Unique: one ledger
            // posting settles one payable.
            $table->timestamp('settled_at')->nullable();
            $table->string('settlement_reference', 64)->nullable()->unique();

            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index('order_id');
        });

        Schema::create('supplier_payable_reversals', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('supplier_payable_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_return_item_id')->constrained('order_return_items')->restrictOnDelete();

            $table->unsignedInteger('quantity');
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);

            $table->text('reason');
            $table->string('idempotency_key', 160)->unique();

            // Set by P13-23 when a settled amount has been taken back from the
            // Supplier wallet; null until then, and for reversals of unsettled
            // payables, which never left Feriwala.
            $table->string('settlement_reversal_reference', 64)->nullable();

            $table->timestamp('created_at');

            // One return line reverses one payable once.
            $table->unique(['supplier_payable_id', 'order_return_item_id']);
        });

        Schema::create('supplier_payable_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payable_id')->constrained()->restrictOnDelete();

            $table->string('previous_status', 24)->nullable();
            $table->string('new_status', 24);
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->string('source', 16);

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();

            $table->index(['supplier_payable_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_payables
                ADD CONSTRAINT supplier_payables_status_known CHECK (
                    status IN ('pending', 'eligible', 'settled', 'partially_reversed', 'reversed', 'cancelled', 'on_hold')
                ),
                ADD CONSTRAINT supplier_payables_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT supplier_payables_rate_not_negative CHECK (supplier_rate_minor >= 0),
                -- Exact minor-unit arithmetic, held by the database as well.
                ADD CONSTRAINT supplier_payables_gross_adds_up CHECK (gross_amount_minor = quantity * supplier_rate_minor),
                ADD CONSTRAINT supplier_payables_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT supplier_payables_event_known CHECK (
                    triggering_event IN ('order_placed')
                ),
                ADD CONSTRAINT supplier_payables_eligible_is_earned CHECK (
                    eligible_at IS NULL OR (delivered_at IS NOT NULL AND payment_settled_at IS NOT NULL)
                ),
                ADD CONSTRAINT supplier_payables_hold_is_recorded CHECK (
                    status <> 'on_hold' OR (held_at IS NOT NULL AND hold_reason IS NOT NULL)
                ),
                ADD CONSTRAINT supplier_payables_cancellation_is_recorded CHECK (
                    status <> 'cancelled' OR cancelled_at IS NOT NULL
                ),
                -- Nothing is settled without the reference of the posting that did it.
                ADD CONSTRAINT supplier_payables_settlement_is_recorded CHECK (
                    (settled_at IS NULL) = (settlement_reference IS NULL)
                    AND (status <> 'settled' OR settled_at IS NOT NULL)
                );

            CREATE TRIGGER supplier_payables_locked_columns
                BEFORE UPDATE OF
                    public_id, reference, supplier_id, order_id, order_item_id, supplier_offer_id,
                    supplier_offer_price_change_id, quantity, supplier_rate_minor, gross_amount_minor,
                    currency_code, triggering_event, idempotency_key
                ON supplier_payables
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'supplier_id', 'order_id', 'order_item_id', 'supplier_offer_id',
                    'supplier_offer_price_change_id', 'quantity', 'supplier_rate_minor', 'gross_amount_minor',
                    'currency_code', 'triggering_event', 'idempotency_key'
                );

            CREATE OR REPLACE FUNCTION feriwala_supplier_payable_is_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'a Supplier payable is never deleted: it is cancelled or reversed'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_payables_never_deleted
                BEFORE DELETE ON supplier_payables
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_payable_is_never_deleted();

            -- The payable must be for the order line's own Supplier, offer, price
            -- version, quantity and rate: the snapshot is the single source.
            CREATE OR REPLACE FUNCTION feriwala_supplier_payable_matches_line() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM order_items
                    WHERE id = NEW.order_item_id
                      AND order_id = NEW.order_id
                      AND supplier_id = NEW.supplier_id
                      AND supplier_offer_id = NEW.supplier_offer_id
                      AND supplier_offer_price_change_id = NEW.supplier_offer_price_change_id
                      AND supplier_allocated_quantity = NEW.quantity
                      AND supplier_rate_minor = NEW.supplier_rate_minor
                      AND supplier_currency_code = NEW.currency_code
                ) THEN
                    RAISE EXCEPTION 'a Supplier payable must match the allocation snapshot of its order line'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_payables_match_their_line
                BEFORE INSERT ON supplier_payables
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_payable_matches_line();

            ALTER TABLE supplier_payable_reversals
                ADD CONSTRAINT supplier_payable_reversals_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT supplier_payable_reversals_amount_positive CHECK (amount_minor > 0),
                ADD CONSTRAINT supplier_payable_reversals_reason_present CHECK (length(btrim(reason)) > 0);

            /*
             * Reversals together can never exceed the payable they reverse — in
             * quantity or in amount — and each is exactly quantity × Supplier Rate.
             * The payable row is locked first, so racing reversals queue and the
             * later one sees the earlier one's total.
             */
            CREATE OR REPLACE FUNCTION feriwala_supplier_payable_reversal_fits() RETURNS trigger AS $$
            DECLARE
                payable supplier_payables%ROWTYPE;
                reversed_quantity bigint;
                reversed_amount bigint;
            BEGIN
                SELECT * INTO payable FROM supplier_payables WHERE id = NEW.supplier_payable_id FOR UPDATE;

                IF payable.currency_code <> NEW.currency_code THEN
                    RAISE EXCEPTION 'a reversal is in the currency of its payable'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF NEW.amount_minor <> NEW.quantity * payable.supplier_rate_minor THEN
                    RAISE EXCEPTION 'a reversal is exactly its quantity at the payable''s Supplier Rate'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                SELECT COALESCE(SUM(quantity), 0), COALESCE(SUM(amount_minor), 0)
                    INTO reversed_quantity, reversed_amount
                    FROM supplier_payable_reversals WHERE supplier_payable_id = NEW.supplier_payable_id;

                IF reversed_quantity + NEW.quantity > payable.quantity
                   OR reversed_amount + NEW.amount_minor > payable.gross_amount_minor THEN
                    RAISE EXCEPTION 'reversals cannot exceed the original Supplier payable'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_payable_reversals_fit_their_payable
                BEFORE INSERT ON supplier_payable_reversals
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_payable_reversal_fits();

            CREATE OR REPLACE FUNCTION feriwala_supplier_payable_reversals_immutable() RETURNS trigger AS $$
            BEGIN
                -- The one thing P13-23 may write onto a reversal: the reference of
                -- the wallet posting that took a settled amount back.
                IF TG_OP = 'UPDATE'
                   AND OLD.settlement_reversal_reference IS NULL
                   AND NEW.settlement_reversal_reference IS NOT NULL
                   AND (to_jsonb(NEW) - 'settlement_reversal_reference') = (to_jsonb(OLD) - 'settlement_reversal_reference') THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'supplier_payable_reversals is append-only'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_payable_reversals_no_update
                BEFORE UPDATE ON supplier_payable_reversals
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_payable_reversals_immutable();

            CREATE TRIGGER supplier_payable_reversals_no_delete
                BEFORE DELETE ON supplier_payable_reversals
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_payable_reversals_immutable();

            ALTER TABLE supplier_payable_status_history
                ADD CONSTRAINT supplier_payable_status_history_source_known CHECK (
                    source IN ('system', 'staff', 'scheduler', 'payment_gateway')
                ),
                ADD CONSTRAINT supplier_payable_status_history_is_a_change CHECK (
                    previous_status IS DISTINCT FROM new_status
                );

            CREATE TRIGGER supplier_payable_status_history_no_update
                BEFORE UPDATE ON supplier_payable_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER supplier_payable_status_history_no_delete
                BEFORE DELETE ON supplier_payable_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_payable_status_history_no_delete ON supplier_payable_status_history;
            DROP TRIGGER IF EXISTS supplier_payable_status_history_no_update ON supplier_payable_status_history;
            DROP TRIGGER IF EXISTS supplier_payable_reversals_no_delete ON supplier_payable_reversals;
            DROP TRIGGER IF EXISTS supplier_payable_reversals_no_update ON supplier_payable_reversals;
            DROP TRIGGER IF EXISTS supplier_payable_reversals_fit_their_payable ON supplier_payable_reversals;
            DROP FUNCTION IF EXISTS feriwala_supplier_payable_reversals_immutable();
            DROP FUNCTION IF EXISTS feriwala_supplier_payable_reversal_fits();
            DROP TRIGGER IF EXISTS supplier_payables_match_their_line ON supplier_payables;
            DROP FUNCTION IF EXISTS feriwala_supplier_payable_matches_line();
            DROP TRIGGER IF EXISTS supplier_payables_never_deleted ON supplier_payables;
            DROP FUNCTION IF EXISTS feriwala_supplier_payable_is_never_deleted();
            DROP TRIGGER IF EXISTS supplier_payables_locked_columns ON supplier_payables;
        SQL);

        Schema::dropIfExists('supplier_payable_status_history');
        Schema::dropIfExists('supplier_payable_reversals');
        Schema::dropIfExists('supplier_payables');
    }
};
