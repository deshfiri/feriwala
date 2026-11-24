<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin order revisions (D38): an order changes only through an append-only
 * revision, and its identity never changes at all.
 *
 *   - `order_revisions` — one row per applied revision: who asked, who applied,
 *     why, the order before, what was asked for, what the server calculated,
 *     the money and stock it moved and what it means for payment and courier.
 *   - `order_revision_lines` — each line the revision added, removed or
 *     replaced, naming the old and the new `order_items` row.
 *   - `order_revision_adjustments` — every compensating record the revision
 *     caused (a payment re-recorded, a balance-due payment, a refund request,
 *     a reservation released or taken, a payable cancelled or accrued, …),
 *     pointing at that record, which keeps its own state.
 *
 * All three are append-only by trigger, with no exception.
 *
 * **The order.** Its identity columns keep `orders_locked_columns` exactly as
 * before. The columns a revision may correct — customer, addresses, money, the
 * D37 delivery snapshot, coupon — leave that trigger for one of their own, which
 * accepts a change only when `revision_number` rises by exactly one in the same
 * statement and an applied revision for that order and number records the old
 * values as its before snapshot and the new values as its final snapshot,
 * column by column. Every other change is refused as it always was.
 *
 * **Its lines.** Still never updated or deleted, with one more permitted move
 * beside the exchange reservation: `superseded_by_revision_id`, set once from
 * empty to an applied revision of the same order that names this line among
 * its own revision lines. The corrected line is a new row carrying
 * `introduced_by_revision_id`.
 */
return new class extends Migration
{
    /** Order columns nothing may ever change, a revision included (D38 "Never revisable"). */
    private const IDENTITY_COLUMNS = [
        'public_id', 'reference', 'source', 'business_account_id', 'website_id', 'payment_id',
        'idempotency_key', 'checkout_fingerprint', 'currency_code', 'placed_at', 'intended_resale_channel',
    ];

    /** Order columns only an applied revision may change (D38 "How the database holds it"). */
    private const REVISABLE_COLUMNS = [
        'customer', 'billing_address', 'shipping_address',
        'subtotal', 'discount', 'delivery',
        'delivery_area', 'delivery_district_id', 'delivery_fee_rule_id', 'delivery_tax', 'delivery_tax_mode',
        'tax', 'tax_included', 'cod_fee', 'total', 'coupon_code',
    ];

    /** The order columns locked before this migration, restored by `down()`. */
    private const PREVIOUSLY_LOCKED_COLUMNS = [
        'public_id', 'reference', 'source', 'business_account_id', 'website_id', 'payment_id',
        'idempotency_key', 'checkout_fingerprint', 'customer', 'billing_address', 'shipping_address',
        'currency_code', 'subtotal', 'discount', 'delivery', 'tax', 'tax_included', 'cod_fee', 'total',
        'coupon_code', 'placed_at', 'intended_resale_channel',
    ];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // How many revisions have been applied; 0 is the order as placed.
            $table->unsignedInteger('revision_number')->default(0)->after('status');
        });

        Schema::create('order_revisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('status', 24);

            // Which parts of the order it touched: customer, addresses, items, pricing, delivery, payment_method, note.
            $table->jsonb('sections');

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->text('internal_note')->nullable();

            // The order and its effective lines before; the staff's edits as
            // sent; and what the server calculated and applied.
            $table->jsonb('before_snapshot');
            $table->jsonb('requested_snapshot');
            $table->jsonb('final_snapshot');

            $table->char('currency_code', 3)->default('BDT');
            $table->decimal('total_before', 19, 2);
            $table->decimal('total_after', 19, 2);
            // Signed: negative when the order comes to less than it did.
            $table->decimal('total_difference', 19, 2);

            // Per product variation and source: quantity held before and after.
            $table->jsonb('stock_difference');
            $table->string('payment_consequence', 40);
            $table->string('courier_consequence', 40);

            // The preview the staff confirmed, and the request that applied it.
            $table->char('fingerprint', 64);
            $table->string('idempotency_key', 128)->unique();

            $table->timestamp('requested_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['order_id', 'revision_number']);
        });

        Schema::create('order_revision_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_revision_id')->constrained('order_revisions')->restrictOnDelete();
            $table->string('change', 24);
            $table->foreignId('previous_order_item_id')->nullable()->constrained('order_items')->restrictOnDelete();
            $table->foreignId('new_order_item_id')->nullable()->constrained('order_items')->restrictOnDelete();

            // What the line was and became, readable without the lines themselves.
            $table->string('sku', 64);
            $table->string('product_name', 160);
            $table->string('variant_label')->nullable();
            $table->integer('quantity_before')->nullable();
            $table->integer('quantity_after')->nullable();
            $table->char('currency_code', 3)->default('BDT');
            $table->decimal('unit_price_before', 19, 2)->nullable();
            $table->decimal('unit_price_after', 19, 2)->nullable();
            $table->decimal('line_total_before', 19, 2)->nullable();
            $table->decimal('line_total_after', 19, 2)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('previous_order_item_id');
        });

        Schema::create('order_revision_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_revision_id')->constrained('order_revisions')->restrictOnDelete();
            $table->string('kind', 48);
            $table->char('currency_code', 3)->nullable();
            $table->decimal('amount', 19, 2)->nullable();
            // Money or stock towards the platform (in) or away from it (out).
            $table->string('direction', 8)->nullable();

            // The record this adjustment is, which keeps its own state.
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('reference', 128)->nullable();
            $table->jsonb('detail');
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('superseded_by_revision_id')->nullable()->constrained('order_revisions')->restrictOnDelete();
            $table->foreignId('introduced_by_revision_id')->nullable()->constrained('order_revisions')->restrictOnDelete();
        });

        $identity = implode(', ', self::IDENTITY_COLUMNS);
        $identityArguments = $this->quoted(self::IDENTITY_COLUMNS);
        $revisable = implode(', ', [...self::REVISABLE_COLUMNS, 'revision_number']);
        $revisableArguments = $this->quoted(self::REVISABLE_COLUMNS);

        DB::connection()->getPdo()->exec(<<<SQL
            ALTER TABLE orders
                ADD CONSTRAINT orders_revision_number_not_negative CHECK (revision_number >= 0);

            ALTER TABLE order_revisions
                ADD CONSTRAINT order_revisions_number_positive CHECK (revision_number >= 1),
                ADD CONSTRAINT order_revisions_status_known CHECK (status IN ('applied')),
                ADD CONSTRAINT order_revisions_applied_is_recorded CHECK (
                    status <> 'applied' OR (applied_by IS NOT NULL AND applied_at IS NOT NULL)
                ),
                ADD CONSTRAINT order_revisions_reason_given CHECK (char_length(btrim(reason)) >= 10),
                ADD CONSTRAINT order_revisions_sections_listed CHECK (
                    jsonb_typeof(sections) = 'array' AND jsonb_array_length(sections) >= 1
                ),
                ADD CONSTRAINT order_revisions_snapshots_carry_order CHECK (
                    jsonb_typeof(before_snapshot -> 'order') = 'object'
                    AND jsonb_typeof(final_snapshot -> 'order') = 'object'
                ),
                ADD CONSTRAINT order_revisions_difference_adds_up CHECK (total_difference = total_after - total_before),
                ADD CONSTRAINT order_revisions_totals_not_negative CHECK (total_before >= 0 AND total_after >= 0),
                ADD CONSTRAINT order_revisions_payment_consequence_known CHECK (payment_consequence IN (
                    'none', 'payment_amount_updated', 'cod_amount_updated', 'balance_due', 'refund_due'
                )),
                ADD CONSTRAINT order_revisions_courier_consequence_known CHECK (courier_consequence IN (
                    'none', 'not_booked', 'recorded_not_sent'
                ));

            ALTER TABLE order_revision_lines
                ADD CONSTRAINT order_revision_lines_change_known CHECK (change IN (
                    'added', 'removed', 'quantity_changed', 'variant_changed', 'price_changed'
                )),
                ADD CONSTRAINT order_revision_lines_shape CHECK (
                    (change = 'added' AND previous_order_item_id IS NULL AND new_order_item_id IS NOT NULL
                        AND quantity_after > 0)
                    OR (change = 'removed' AND previous_order_item_id IS NOT NULL AND new_order_item_id IS NULL
                        AND quantity_before > 0)
                    OR (change IN ('quantity_changed', 'variant_changed', 'price_changed')
                        AND previous_order_item_id IS NOT NULL AND new_order_item_id IS NOT NULL
                        AND quantity_before > 0 AND quantity_after > 0)
                ),
                ADD CONSTRAINT order_revision_lines_amounts_not_negative CHECK (
                    COALESCE(unit_price_before, 0) >= 0 AND COALESCE(unit_price_after, 0) >= 0
                    AND COALESCE(line_total_before, 0) >= 0 AND COALESCE(line_total_after, 0) >= 0
                );

            ALTER TABLE order_revision_adjustments
                ADD CONSTRAINT order_revision_adjustments_kind_known CHECK (kind IN (
                    'payment_amount_updated', 'balance_due_payment', 'refund_request',
                    'wallet_debit', 'wallet_credit',
                    'stock_released', 'stock_reserved', 'stock_returned_from_processing',
                    'allocation_stood_down', 'supplier_payable_cancelled', 'supplier_payable_accrued',
                    'invoice_adjustment', 'courier_follow_up'
                )),
                ADD CONSTRAINT order_revision_adjustments_direction_known CHECK (
                    direction IS NULL OR direction IN ('in', 'out')
                ),
                ADD CONSTRAINT order_revision_adjustments_amount_not_negative CHECK (amount IS NULL OR amount >= 0),
                ADD CONSTRAINT order_revision_adjustments_amount_has_currency CHECK (
                    amount IS NULL OR currency_code IS NOT NULL
                ),
                ADD CONSTRAINT order_revision_adjustments_detail_object CHECK (jsonb_typeof(detail) = 'object');

            CREATE TRIGGER order_revisions_no_update BEFORE UPDATE ON order_revisions
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
            CREATE TRIGGER order_revisions_no_delete BEFORE DELETE ON order_revisions
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
            CREATE TRIGGER order_revision_lines_no_update BEFORE UPDATE ON order_revision_lines
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
            CREATE TRIGGER order_revision_lines_no_delete BEFORE DELETE ON order_revision_lines
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
            CREATE TRIGGER order_revision_adjustments_no_update BEFORE UPDATE ON order_revision_adjustments
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
            CREATE TRIGGER order_revision_adjustments_no_delete BEFORE DELETE ON order_revision_adjustments
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            -- A revision line belongs to the revision's own order, on both sides.
            CREATE OR REPLACE FUNCTION feriwala_order_revision_line_matches_order() RETURNS trigger AS \$\$
            DECLARE
                revision_order bigint;
            BEGIN
                SELECT order_id INTO revision_order FROM order_revisions WHERE id = NEW.order_revision_id;

                IF (NEW.previous_order_item_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM order_items WHERE id = NEW.previous_order_item_id AND order_id = revision_order
                            AND superseded_by_revision_id IS NULL
                    ))
                    OR (NEW.new_order_item_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM order_items WHERE id = NEW.new_order_item_id AND order_id = revision_order
                            AND introduced_by_revision_id = NEW.order_revision_id
                    )) THEN
                    RAISE EXCEPTION 'order_revision_lines: a line must be an effective line of the revision''s own order (D38)'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER order_revision_lines_match_order BEFORE INSERT ON order_revision_lines
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_revision_line_matches_order();

            -- A line a revision introduces belongs to that revision's order.
            CREATE OR REPLACE FUNCTION feriwala_order_item_introduced_by_own_revision() RETURNS trigger AS \$\$
            BEGIN
                IF NEW.introduced_by_revision_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM order_revisions WHERE id = NEW.introduced_by_revision_id AND order_id = NEW.order_id
                ) THEN
                    RAISE EXCEPTION 'order_items: a revised line belongs to its own order''s revision (D38)'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER order_items_introduced_by_own_revision BEFORE INSERT ON order_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_item_introduced_by_own_revision();

            /*
             * The revisable order columns change only together with the next
             * revision number, and only to exactly what that applied revision
             * says they were and became. Compared as text, column by column, so
             * a decimal string in the snapshot and the numeric column agree, and
             * a jsonb address compares in its canonical form.
             */
            CREATE OR REPLACE FUNCTION feriwala_order_revision_is_applied() RETURNS trigger AS \$\$
            DECLARE
                col text;
                old_json jsonb := to_jsonb(OLD);
                new_json jsonb := to_jsonb(NEW);
                changed boolean := false;
                before_order jsonb;
                after_order jsonb;
            BEGIN
                FOREACH col IN ARRAY TG_ARGV LOOP
                    IF (old_json -> col) IS DISTINCT FROM (new_json -> col) THEN
                        changed := true;
                    END IF;
                END LOOP;

                IF NOT changed AND NEW.revision_number = OLD.revision_number THEN
                    RETURN NEW;
                END IF;

                IF NEW.revision_number IS DISTINCT FROM OLD.revision_number + 1 THEN
                    RAISE EXCEPTION 'order % changes only through its next applied revision (D38)', OLD.reference
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                SELECT before_snapshot -> 'order', final_snapshot -> 'order' INTO before_order, after_order
                    FROM order_revisions
                    WHERE order_id = NEW.id AND revision_number = NEW.revision_number AND status = 'applied';

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'order % has no applied revision %: its figures cannot change (D38)', OLD.reference, NEW.revision_number
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                FOREACH col IN ARRAY TG_ARGV LOOP
                    IF (before_order ->> col) IS DISTINCT FROM (old_json ->> col)
                        OR (after_order ->> col) IS DISTINCT FROM (new_json ->> col) THEN
                        RAISE EXCEPTION 'order %: % does not match applied revision % (D38)', OLD.reference, col, NEW.revision_number
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END LOOP;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS orders_locked_columns ON orders;
            DROP TRIGGER IF EXISTS orders_delivery_snapshot_locked ON orders;

            CREATE TRIGGER orders_locked_columns
                BEFORE UPDATE OF {$identity} ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked({$identityArguments});

            CREATE TRIGGER orders_revisable_columns_follow_revision
                BEFORE UPDATE OF {$revisable} ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_revision_is_applied({$revisableArguments});
        SQL);

        DB::connection()->getPdo()->exec($this->historyRowGuard(withRevisionSupersession: true));
    }

    public function down(): void
    {
        DB::connection()->getPdo()->exec($this->historyRowGuard(withRevisionSupersession: false));

        $locked = implode(', ', self::PREVIOUSLY_LOCKED_COLUMNS);
        $lockedArguments = $this->quoted(self::PREVIOUSLY_LOCKED_COLUMNS);

        DB::connection()->getPdo()->exec(<<<SQL
            DROP TRIGGER IF EXISTS orders_revisable_columns_follow_revision ON orders;
            DROP TRIGGER IF EXISTS orders_locked_columns ON orders;
            DROP TRIGGER IF EXISTS order_items_introduced_by_own_revision ON order_items;
            DROP FUNCTION IF EXISTS feriwala_order_revision_is_applied();
            DROP FUNCTION IF EXISTS feriwala_order_item_introduced_by_own_revision();

            CREATE TRIGGER orders_locked_columns
                BEFORE UPDATE OF {$locked} ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked({$lockedArguments});

            CREATE TRIGGER orders_delivery_snapshot_locked
                BEFORE UPDATE OF delivery_area, delivery_district_id, delivery_fee_rule_id, delivery_tax, delivery_tax_mode ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'delivery_area', 'delivery_district_id', 'delivery_fee_rule_id', 'delivery_tax', 'delivery_tax_mode'
                );

            ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_revision_number_not_negative;
        SQL);

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('introduced_by_revision_id');
            $table->dropConstrainedForeignId('superseded_by_revision_id');
        });

        Schema::dropIfExists('order_revision_adjustments');
        Schema::dropIfExists('order_revision_lines');
        Schema::dropIfExists('order_revisions');

        DB::unprepared('DROP FUNCTION IF EXISTS feriwala_order_revision_line_matches_order();');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('revision_number');
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function quoted(array $columns): string
    {
        return implode(', ', array_map(fn (string $column) => "'{$column}'", $columns));
    }

    /**
     * `feriwala_history_row_guard()` as 2026_11_16_110000 left it (snapshot
     * filling, the foreign key's SET NULL, an exchange line's reservation),
     * with — when asked — one more permitted move on `order_items`: a line
     * superseded, once, by an applied revision of its own order that names it.
     */
    private function historyRowGuard(bool $withRevisionSupersession): string
    {
        $supersession = $withRevisionSupersession ? <<<'SQL'

                        -- A line an applied revision of its own order replaced or removed (D38).
                        IF TG_TABLE_NAME = 'order_items'
                            AND key = 'superseded_by_revision_id'
                            AND jsonb_typeof(old_json -> key) = 'null'
                            AND jsonb_typeof(new_json -> key) <> 'null'
                            AND EXISTS (
                                SELECT 1
                                FROM order_revisions r
                                JOIN order_revision_lines l ON l.order_revision_id = r.id
                                WHERE r.id = NEW.superseded_by_revision_id
                                    AND r.order_id = NEW.order_id
                                    AND r.status = 'applied'
                                    AND l.previous_order_item_id = NEW.id
                            ) THEN
                            permitted := true;
                        END IF;
        SQL : '';

        return <<<SQL
            CREATE OR REPLACE FUNCTION feriwala_history_row_guard()
            RETURNS trigger LANGUAGE plpgsql AS \$function\$
            DECLARE
                spec text;
                col text;
                key text;
                permitted boolean;
                old_json jsonb := to_jsonb(OLD);
                new_json jsonb := to_jsonb(NEW);
            BEGIN
                FOR key IN SELECT jsonb_object_keys(new_json) LOOP
                    IF (old_json -> key) IS NOT DISTINCT FROM (new_json -> key) THEN
                        CONTINUE;
                    END IF;

                    permitted := false;

                        -- An exchange line gets its stock hold once the returned goods are approved.
                        IF TG_TABLE_NAME = 'order_items'
                            AND key = 'stock_reservation_id'
                            AND jsonb_typeof(old_json -> key) = 'null'
                            AND jsonb_typeof(new_json -> key) <> 'null'
                            AND EXISTS (SELECT 1 FROM orders WHERE id = NEW.order_id AND source = 'exchange') THEN
                            permitted := true;
                        END IF;
                    {$supersession}

                    FOREACH spec IN ARRAY TG_ARGV LOOP
                        col := split_part(spec, ':', 1);

                        -- Filling an empty snapshot with what the product says now.
                        IF key = col || '_snapshot'
                            AND jsonb_typeof(old_json -> key) = 'null'
                            AND (new_json -> key) IS NOT DISTINCT FROM feriwala_snapshot_of(split_part(spec, ':', 2), (new_json ->> col)::bigint) THEN
                            permitted := true;
                        END IF;

                        -- The foreign key's own SET NULL, once the snapshot is in place.
                        IF key = col
                            AND pg_trigger_depth() > 1
                            AND jsonb_typeof(old_json -> key) <> 'null'
                            AND jsonb_typeof(new_json -> key) = 'null'
                            AND jsonb_typeof(new_json -> (col || '_snapshot')) = 'object' THEN
                            permitted := true;
                        END IF;
                    END LOOP;

                    IF NOT permitted THEN
                        RAISE EXCEPTION '% is a snapshot of what happened: changing % is not permitted.', TG_TABLE_NAME, key
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END LOOP;

                RETURN NEW;
            END;
            \$function\$;
        SQL;
    }
};
