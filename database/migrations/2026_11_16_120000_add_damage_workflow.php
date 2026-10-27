<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The damage workflow (D35): a finding about goods, who bears it, what it costs,
 * and where the damaged item goes.
 *
 * A damage case is built on what exists, not beside it. It points at the order,
 * the order line and — when the goods came back — the return case and its line,
 * and it moves stock through the stock ledgers the returns already use. It is
 * never a standalone product record.
 *
 * **What a damage is worth is frozen at the line.** `order_items.suggested_unit_price`
 * is the platform's suggested selling price when the order was placed, written once
 * with the line like every other snapshot on it (`order_items_history_guard` refuses
 * every later update). The most a damage can cost is that price times the damaged
 * quantity — never the Partner's own store price, never a price edited afterwards.
 * A line placed before this column existed has none; its value falls back to what
 * `platform_retained_amount` already froze, and with neither, to nothing: nothing is
 * invented for an order that never recorded it, and the case can then only be
 * written off.
 *
 * Money is flat Taka in NUMERIC(19,2), like every other money column (D26).
 *
 * `order_damage_charges` is the accounting record of what a damage costs and who
 * bears it, with the same shape and the same rules as `order_return_charges`: a
 * Partner-borne charge is a wallet transaction (or an outstanding liability when
 * the wallet cannot cover it and must not go negative), a Banij-borne one is
 * recorded here and nowhere else, rows are never deleted, and a mistake is answered
 * by a reversal row that points at the charge it puts right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('suggested_unit_price', 19, 2)->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_items
                ADD CONSTRAINT order_items_suggested_unit_price_not_negative
                CHECK (suggested_unit_price IS NULL OR suggested_unit_price >= 0);
        SQL);

        Schema::create('order_damages', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();

            // Whose, and which sale it came from.
            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();
            $table->foreignId('website_id')->nullable()->constrained('websites')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->foreignId('order_return_id')->nullable()->constrained('order_returns')->restrictOnDelete();
            $table->foreignId('order_return_item_id')->nullable()->constrained('order_return_items')->restrictOnDelete();

            // return_inspection: found on goods that came back · delivered_report: a
            // delivered product reported as damaged.
            $table->string('source', 24);
            $table->boolean('parcel_expected')->default(false);
            // Whether the goods are in Banij's hands, and so in a stock bucket.
            $table->boolean('in_custody')->default(false);
            // The bucket those units sit in until inspection says otherwise.
            $table->string('held_bucket', 16)->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();

            // Frozen from the order line when the case is opened.
            $table->string('sku', 64);
            $table->string('product_name', 160);
            $table->string('variant_label', 255)->nullable();
            $table->unsignedInteger('quantity');

            $table->string('damage_condition', 24);
            $table->string('cause', 32)->nullable();
            $table->text('description')->nullable();
            $table->jsonb('evidence')->default('[]');

            $table->string('status', 32);
            $table->string('initiated_by_type', 16);

            // Decided at liability review, kept in `order_damage_responsibility_changes`.
            $table->string('responsible_party', 16)->nullable();

            // What it is worth, frozen when the case is opened.
            $table->char('currency_code', 3)->default('BDT');
            $table->string('valuation_basis', 24);
            $table->decimal('valuation_unit_price', 19, 2)->nullable();
            $table->decimal('max_recoverable_value', 19, 2);
            $table->jsonb('valuation_snapshot');
            $table->decimal('approved_loss_amount', 19, 2)->nullable();
            $table->text('approval_reason')->nullable();
            $table->text('write_off_reason')->nullable();

            $table->text('inspection_note')->nullable();

            $table->foreignId('reported_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reported_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('liability_decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('liability_decided_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('finalized_at')->nullable();

            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamps();

            $table->index(['business_account_id', 'status']);
            $table->index(['order_id']);
        });

        Schema::create('order_damage_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_damage_id')->constrained('order_damages')->restrictOnDelete();

            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->string('source', 16);

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();

            $table->index(['order_damage_id', 'id']);
        });

        Schema::create('order_damage_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_damage_id')->constrained('order_damages')->restrictOnDelete();

            // restock · damaged · quarantine — the same three a returned line may end in.
            $table->string('disposition', 16);
            $table->unsignedInteger('quantity');
            $table->string('from_bucket', 16)->nullable();
            $table->string('to_bucket', 16)->nullable();

            // The one stock movement that put these units where they are, when any moved.
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('partner_stock_movement_id')->nullable()->constrained('partner_stock_movements')->restrictOnDelete();
            $table->timestamp('moved_at')->nullable();
            $table->timestamps();

            $table->unique(['order_damage_id', 'disposition']);
        });

        Schema::create('order_damage_responsibility_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_damage_id')->constrained('order_damages')->restrictOnDelete();
            $table->string('previous_party', 16)->nullable();
            $table->string('new_party', 16);
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->text('reason');

            $table->index(['order_damage_id', 'id']);
        });

        Schema::create('order_damage_charges', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_damage_id')->constrained('order_damages')->restrictOnDelete();
            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();

            // damage_loss · damage_delivery
            $table->string('kind', 24);
            $table->string('entry_type', 12)->default('charge');
            $table->foreignId('reverses_charge_id')->nullable()->constrained('order_damage_charges')->restrictOnDelete();

            $table->string('bearer', 16);
            $table->decimal('amount', 19, 2);
            $table->char('currency_code', 3)->default('BDT');
            $table->string('status', 16);

            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 120)->unique();
            $table->timestamp('posted_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['order_damage_id', 'id']);
            $table->index(['business_account_id', 'status']);
        });

        Schema::create('order_damage_dispatches', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_damage_id')->constrained('order_damages')->restrictOnDelete();
            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();

            $table->unsignedInteger('quantity');

            // The address as it stood when it was asked for, and the Partner's own.
            $table->foreignId('shared_address_id')->constrained('shared_addresses')->restrictOnDelete();
            $table->jsonb('address_snapshot');

            // Worked out by the delivery-charge service, never taken from a request.
            $table->char('currency_code', 3)->default('BDT');
            $table->decimal('delivery_charge', 19, 2);
            $table->jsonb('delivery_calculation');

            // requested · dispatched · received_by_partner
            $table->string('status', 24);
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('courier_provider_id')->nullable()->constrained('courier_providers')->restrictOnDelete();
            $table->string('tracking_number', 120)->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('partner_stock_movement_id')->nullable()->constrained('partner_stock_movements')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->timestamps();

            // Once: a damaged item is sent to its owner one time.
            $table->unique('order_damage_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_damages
                ADD CONSTRAINT order_damages_status_known CHECK (
                    status IN (
                        'reported', 'awaiting_receipt', 'received', 'under_inspection', 'liability_review',
                        'approved', 'rejected', 'financial_posting_pending', 'finalized',
                        'dispatch_requested', 'dispatched', 'received_by_partner'
                    )
                ),
                ADD CONSTRAINT order_damages_source_known CHECK (source IN ('return_inspection', 'delivered_report')),
                ADD CONSTRAINT order_damages_initiator_known CHECK (initiated_by_type IN ('partner', 'staff')),
                ADD CONSTRAINT order_damages_responsible_party_known CHECK (
                    responsible_party IS NULL OR responsible_party IN ('partner', 'banij')
                ),
                ADD CONSTRAINT order_damages_condition_known CHECK (
                    damage_condition IN ('broken', 'torn', 'stained', 'defective', 'missing_parts', 'water_damaged', 'crushed', 'other')
                ),
                ADD CONSTRAINT order_damages_cause_known CHECK (
                    cause IS NULL OR cause IN (
                        'packing', 'warehouse_handling', 'courier_transit', 'partner_handling',
                        'customer_misuse', 'manufacturing_defect', 'unknown'
                    )
                ),
                ADD CONSTRAINT order_damages_held_bucket_known CHECK (
                    held_bucket IS NULL OR held_bucket IN ('returned', 'damaged')
                ),
                ADD CONSTRAINT order_damages_valuation_basis_known CHECK (
                    valuation_basis IN ('suggested_price', 'platform_retained', 'none')
                ),
                ADD CONSTRAINT order_damages_quantity_is_positive CHECK (quantity > 0),
                ADD CONSTRAINT order_damages_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$'),
                -- A damage from returned goods points at the return and its line; one from
                -- a delivered report points at neither.
                ADD CONSTRAINT order_damages_source_matches_links CHECK (
                    (source = 'return_inspection') = (order_return_id IS NOT NULL AND order_return_item_id IS NOT NULL)
                ),
                ADD CONSTRAINT order_damages_custody_has_a_bucket CHECK (
                    (in_custody = (held_bucket IS NOT NULL))
                ),
                -- The most a damage can cost is what the line froze, and a basis of
                -- nothing means nothing.
                ADD CONSTRAINT order_damages_value_within_maximum CHECK (
                    max_recoverable_value >= 0
                    AND (valuation_basis <> 'none' OR max_recoverable_value = 0)
                    AND (approved_loss_amount IS NULL OR (approved_loss_amount >= 0 AND approved_loss_amount <= max_recoverable_value))
                ),
                -- A loss of nothing is a write-off, and a write-off says why.
                ADD CONSTRAINT order_damages_zero_loss_is_a_write_off CHECK (
                    approved_loss_amount IS NULL
                    OR approved_loss_amount > 0
                    OR length(btrim(coalesce(write_off_reason, ''))) > 0
                ),
                -- Decided, then posted: neither before inspection is complete.
                ADD CONSTRAINT order_damages_decision_follows_inspection CHECK (
                    status NOT IN ('approved', 'financial_posting_pending', 'finalized', 'dispatch_requested', 'dispatched', 'received_by_partner')
                    OR (inspected_at IS NOT NULL AND responsible_party IS NOT NULL AND approved_loss_amount IS NOT NULL
                        AND liability_decided_at IS NOT NULL AND cause IS NOT NULL)
                ),
                ADD CONSTRAINT order_damages_finalized_is_dated CHECK (
                    status NOT IN ('finalized', 'dispatch_requested', 'dispatched', 'received_by_partner')
                    OR finalized_at IS NOT NULL
                ),
                -- A parcel that was expected has arrived before anything is inspected.
                ADD CONSTRAINT order_damages_parcel_arrived_before_inspection CHECK (
                    NOT parcel_expected
                    OR status IN ('reported', 'awaiting_receipt', 'rejected')
                    OR received_at IS NOT NULL
                );

            ALTER TABLE order_damage_dispositions
                ADD CONSTRAINT order_damage_dispositions_known CHECK (disposition IN ('restock', 'damaged', 'quarantine')),
                ADD CONSTRAINT order_damage_dispositions_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT order_damage_dispositions_one_movement CHECK (
                    num_nonnulls(stock_movement_id, partner_stock_movement_id) <= 1
                );

            CREATE UNIQUE INDEX order_damage_dispositions_one_central_movement
                ON order_damage_dispositions (stock_movement_id) WHERE stock_movement_id IS NOT NULL;
            CREATE UNIQUE INDEX order_damage_dispositions_one_partner_movement
                ON order_damage_dispositions (partner_stock_movement_id) WHERE partner_stock_movement_id IS NOT NULL;

            -- One live damage for a returned line, and no more units claimed damaged
            -- than the line sold.
            CREATE UNIQUE INDEX order_damages_one_per_returned_line
                ON order_damages (order_return_item_id)
                WHERE order_return_item_id IS NOT NULL AND status <> 'rejected';

            ALTER TABLE order_damage_status_history
                ADD CONSTRAINT order_damage_status_history_source_known CHECK (
                    source IN ('storefront', 'account', 'staff', 'system', 'scheduler', 'payment_gateway')
                ),
                ADD CONSTRAINT order_damage_status_history_is_a_change CHECK (
                    previous_status IS DISTINCT FROM new_status
                );

            ALTER TABLE order_damage_responsibility_changes
                ADD CONSTRAINT order_damage_responsibility_parties_known CHECK (
                    new_party IN ('partner', 'banij') AND (previous_party IS NULL OR previous_party IN ('partner', 'banij'))
                ),
                ADD CONSTRAINT order_damage_responsibility_is_a_change CHECK (previous_party IS DISTINCT FROM new_party),
                ADD CONSTRAINT order_damage_responsibility_has_a_reason CHECK (length(btrim(reason)) > 0);

            ALTER TABLE order_damage_charges
                ADD CONSTRAINT order_damage_charges_kind_known CHECK (kind IN ('damage_loss', 'damage_delivery')),
                ADD CONSTRAINT order_damage_charges_entry_type_known CHECK (entry_type IN ('charge', 'reversal')),
                ADD CONSTRAINT order_damage_charges_bearer_known CHECK (bearer IN ('partner', 'banij')),
                ADD CONSTRAINT order_damage_charges_status_known CHECK (
                    status IN ('recorded', 'posted', 'outstanding', 'settled', 'reversed')
                ),
                ADD CONSTRAINT order_damage_charges_amount_is_positive CHECK (amount > 0),
                ADD CONSTRAINT order_damage_charges_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT order_damage_charges_reason_present CHECK (length(btrim(reason)) > 0),
                ADD CONSTRAINT order_damage_charges_reversal_points_at_a_charge CHECK (
                    (entry_type = 'reversal') = (reverses_charge_id IS NOT NULL)
                ),
                ADD CONSTRAINT order_damage_charges_wallet_matches_status CHECK (
                    (bearer = 'banij' AND wallet_transaction_id IS NULL)
                    OR (status IN ('posted', 'settled') AND wallet_transaction_id IS NOT NULL)
                    OR (status IN ('outstanding', 'reversed', 'recorded'))
                );

            ALTER TABLE order_damage_dispatches
                ADD CONSTRAINT order_damage_dispatches_status_known CHECK (
                    status IN ('requested', 'dispatched', 'received_by_partner')
                ),
                ADD CONSTRAINT order_damage_dispatches_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT order_damage_dispatches_charge_not_negative CHECK (delivery_charge >= 0),
                ADD CONSTRAINT order_damage_dispatches_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT order_damage_dispatches_one_movement CHECK (
                    num_nonnulls(stock_movement_id, partner_stock_movement_id) <= 1
                ),
                ADD CONSTRAINT order_damage_dispatches_dispatch_is_dated CHECK (
                    status = 'requested' OR (dispatched_at IS NOT NULL AND dispatched_by IS NOT NULL)
                ),
                ADD CONSTRAINT order_damage_dispatches_receipt_is_dated CHECK (
                    status <> 'received_by_partner' OR received_at IS NOT NULL
                );

            -- Who, which line and how much never change once a case is opened; nor
            -- does what the goods were worth.
            CREATE TRIGGER order_damages_locked_columns
                BEFORE UPDATE OF public_id, reference, business_account_id, website_id, order_id, order_item_id,
                                 order_return_id, order_return_item_id, source, sku, product_name, variant_label,
                                 quantity, currency_code, valuation_basis, valuation_unit_price,
                                 max_recoverable_value, valuation_snapshot
                ON order_damages
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'business_account_id', 'website_id', 'order_id', 'order_item_id',
                    'order_return_id', 'order_return_item_id', 'source', 'sku', 'product_name', 'variant_label',
                    'quantity', 'currency_code', 'valuation_basis', 'valuation_unit_price',
                    'max_recoverable_value', 'valuation_snapshot'
                );

            -- The approved loss is decided once. After that only a compensating entry
            -- corrects what it cost.
            CREATE OR REPLACE FUNCTION feriwala_damage_loss_is_fixed_once_decided() RETURNS trigger AS $$
            BEGIN
                IF OLD.approved_loss_amount IS NOT NULL
                    AND (NEW.approved_loss_amount IS DISTINCT FROM OLD.approved_loss_amount
                         OR NEW.write_off_reason IS DISTINCT FROM OLD.write_off_reason
                         OR NEW.approval_reason IS DISTINCT FROM OLD.approval_reason) THEN
                    RAISE EXCEPTION 'order_damages %: the approved loss is fixed once decided; correct it with a compensating entry', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_damages_loss_is_fixed
                BEFORE UPDATE ON order_damages
                FOR EACH ROW EXECUTE FUNCTION feriwala_damage_loss_is_fixed_once_decided();

            -- A damage belongs to its order's account, shop and currency, its line to
            -- that order, and no more units are claimed damaged than the line sold or
            -- the returned line brought back.
            CREATE OR REPLACE FUNCTION feriwala_damage_belongs_to_its_order() RETURNS trigger AS $$
            DECLARE
                owning_account bigint;
                owning_website bigint;
                order_currency char(3);
                line_order bigint;
                sold integer;
                claimed integer;
                brought_back integer;
            BEGIN
                SELECT business_account_id, website_id, currency_code INTO owning_account, owning_website, order_currency
                FROM orders WHERE id = NEW.order_id;

                IF owning_account IS DISTINCT FROM NEW.business_account_id
                    OR owning_website IS DISTINCT FROM NEW.website_id THEN
                    RAISE EXCEPTION 'a damage belongs to the account and shop of the order it is for'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF order_currency IS DISTINCT FROM NEW.currency_code THEN
                    RAISE EXCEPTION 'a damage is in the currency of the order it is for'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                SELECT order_id, quantity INTO line_order, sold FROM order_items WHERE id = NEW.order_item_id;

                IF line_order IS DISTINCT FROM NEW.order_id THEN
                    RAISE EXCEPTION 'a damaged line must belong to the order the damage is for'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                SELECT coalesce(sum(quantity), 0) INTO claimed
                FROM order_damages
                WHERE order_item_id = NEW.order_item_id AND id IS DISTINCT FROM NEW.id AND status <> 'rejected';

                IF NEW.status <> 'rejected' AND claimed + NEW.quantity > sold THEN
                    RAISE EXCEPTION 'more of this line would be damaged than was sold'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF NEW.order_return_item_id IS NOT NULL THEN
                    SELECT received_quantity INTO brought_back FROM order_return_items WHERE id = NEW.order_return_item_id;

                    IF NEW.quantity > coalesce(brought_back, 0) THEN
                        RAISE EXCEPTION 'more would be damaged than came back on that returned line'
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_damages_belong_to_their_order
                BEFORE INSERT ON order_damages
                FOR EACH ROW EXECUTE FUNCTION feriwala_damage_belongs_to_its_order();

            -- What a charge *is* is fixed when it is written. Only its state moves.
            CREATE TRIGGER order_damage_charges_locked_columns
                BEFORE UPDATE OF public_id, order_damage_id, business_account_id, kind, entry_type,
                                 reverses_charge_id, bearer, amount, currency_code, reason, idempotency_key
                ON order_damage_charges
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'order_damage_id', 'business_account_id', 'kind', 'entry_type',
                    'reverses_charge_id', 'bearer', 'amount', 'currency_code', 'reason', 'idempotency_key'
                );

            CREATE TRIGGER order_damage_dispatches_locked_columns
                BEFORE UPDATE OF public_id, order_damage_id, business_account_id, quantity, shared_address_id,
                                 address_snapshot, currency_code, delivery_charge, delivery_calculation, idempotency_key
                ON order_damage_dispatches
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'order_damage_id', 'business_account_id', 'quantity', 'shared_address_id',
                    'address_snapshot', 'currency_code', 'delivery_charge', 'delivery_calculation', 'idempotency_key'
                );

            -- A disposition is a decision about units: once written, it is not edited.
            CREATE TRIGGER order_damage_dispositions_locked_columns
                BEFORE UPDATE OF order_damage_id, disposition, quantity, from_bucket, to_bucket
                ON order_damage_dispositions
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'order_damage_id', 'disposition', 'quantity', 'from_bucket', 'to_bucket'
                );

            CREATE OR REPLACE FUNCTION feriwala_damage_records_are_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is never deleted: a correction is a new row that points at what it answers', TG_TABLE_NAME
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_damages_no_delete
                BEFORE DELETE ON order_damages
                FOR EACH ROW EXECUTE FUNCTION feriwala_damage_records_are_never_deleted();
            CREATE TRIGGER order_damage_charges_no_delete
                BEFORE DELETE ON order_damage_charges
                FOR EACH ROW EXECUTE FUNCTION feriwala_damage_records_are_never_deleted();
            CREATE TRIGGER order_damage_dispositions_no_delete
                BEFORE DELETE ON order_damage_dispositions
                FOR EACH ROW EXECUTE FUNCTION feriwala_damage_records_are_never_deleted();
            CREATE TRIGGER order_damage_dispatches_no_delete
                BEFORE DELETE ON order_damage_dispatches
                FOR EACH ROW EXECUTE FUNCTION feriwala_damage_records_are_never_deleted();

            -- History is only ever added to.
            CREATE TRIGGER order_damage_status_history_no_update
                BEFORE UPDATE ON order_damage_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
            CREATE TRIGGER order_damage_status_history_no_delete
                BEFORE DELETE ON order_damage_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
            CREATE TRIGGER order_damage_responsibility_changes_no_update
                BEFORE UPDATE ON order_damage_responsibility_changes
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
            CREATE TRIGGER order_damage_responsibility_changes_no_delete
                BEFORE DELETE ON order_damage_responsibility_changes
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_damage_dispatches');
        Schema::dropIfExists('order_damage_charges');
        Schema::dropIfExists('order_damage_responsibility_changes');
        Schema::dropIfExists('order_damage_dispositions');
        Schema::dropIfExists('order_damage_status_history');
        Schema::dropIfExists('order_damages');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS feriwala_damage_belongs_to_its_order();
            DROP FUNCTION IF EXISTS feriwala_damage_loss_is_fixed_once_decided();
            DROP FUNCTION IF EXISTS feriwala_damage_records_are_never_deleted();
            ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_suggested_unit_price_not_negative;
        SQL);

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('suggested_unit_price');
        });
    }
};
