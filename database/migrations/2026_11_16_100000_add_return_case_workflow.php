<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The return case workflow (D35): who asked, who is responsible, the strict
 * stage-by-stage progression, and the money each case owes.
 *
 * Built on the returns that already exist, not beside them. A return placed
 * before this change keeps `workflow_version` 1 and the three-step flow it was
 * placed under (requested → approved → received → refunded); every return
 * placed after it is version 2 and follows the full progression. Nothing
 * historical is rewritten, and no responsibility or charge is invented for a
 * row that never recorded one — those columns stay NULL.
 *
 * Money is flat Taka in NUMERIC(19,2), like every other money column (D26).
 *
 * `order_return_charges` is the accounting record of what a case costs and who
 * bears it. A charge borne by the Partner is also a wallet transaction (or an
 * outstanding liability when the wallet cannot cover it); a charge borne by
 * Banij is recorded here and nowhere else, because there is no platform wallet.
 * Rows are never deleted and their identity never changes; a mistake is put
 * right by a **reversal row** that points at the charge it answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The longest new status is 'inspection_approved' (19): the column and the
        // history that copies it were sized for the first six.
        DB::unprepared(<<<'SQL'
            ALTER TABLE order_returns ALTER COLUMN status TYPE varchar(32);
            ALTER TABLE order_return_status_history
                ALTER COLUMN previous_status TYPE varchar(32),
                ALTER COLUMN new_status TYPE varchar(32);

            ALTER TABLE order_returns
                DROP CONSTRAINT order_returns_status_known,
                DROP CONSTRAINT order_returns_received_is_dated,
                ADD CONSTRAINT order_returns_status_known CHECK (
                    status IN (
                        'requested', 'approved', 'rejected', 'received', 'refunded', 'cancelled',
                        'waiting_for_parcel', 'parcel_received', 'under_inspection',
                        'inspection_approved', 'inspection_rejected', 'settlement_pending', 'completed'
                    )
                ),
                ADD CONSTRAINT order_returns_received_is_dated CHECK (
                    status NOT IN (
                        'received', 'refunded', 'parcel_received', 'under_inspection',
                        'inspection_approved', 'inspection_rejected', 'settlement_pending', 'completed'
                    ) OR received_at IS NOT NULL
                );
        SQL);

        Schema::table('order_returns', function (Blueprint $table) {
            // 1 = the three-step flow a return was placed under before D35.
            $table->unsignedSmallInteger('workflow_version')->default(1)->after('source');
            $table->string('case_type', 16)->default('return')->after('workflow_version');

            $table->string('initiated_by_type', 16)->nullable()->after('case_type');
            $table->string('on_behalf_of', 16)->nullable()->after('initiated_by_type');
            $table->string('responsible_party', 16)->nullable()->after('on_behalf_of');

            $table->foreignId('inspected_by')->nullable()->after('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('inspected_at')->nullable()->after('received_at');
            $table->string('inspection_outcome', 16)->nullable()->after('inspected_at');
            $table->text('inspection_note')->nullable()->after('inspection_outcome');

            // Snapshotted when the case is opened, never recomputed from a later
            // setting: what the original delivery cost, and what half of it is.
            $table->decimal('original_delivery_charge', 19, 2)->nullable();
            $table->decimal('return_charge', 19, 2)->nullable();
            $table->decimal('exchange_delivery_charge', 19, 2)->nullable();
            $table->decimal('other_adjustments', 19, 2)->nullable();
            $table->text('adjustment_note')->nullable();

            $table->timestamp('completed_at')->nullable()->after('refunded_at');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_returns
                ADD CONSTRAINT order_returns_workflow_known CHECK (workflow_version IN (1, 2)),
                ADD CONSTRAINT order_returns_case_type_known CHECK (case_type IN ('return', 'exchange')),
                ADD CONSTRAINT order_returns_initiator_known CHECK (
                    initiated_by_type IS NULL OR initiated_by_type IN ('partner', 'staff', 'customer')
                ),
                ADD CONSTRAINT order_returns_on_behalf_of_known CHECK (
                    on_behalf_of IS NULL OR on_behalf_of IN ('partner', 'banij')
                ),
                ADD CONSTRAINT order_returns_responsible_party_known CHECK (
                    responsible_party IS NULL OR responsible_party IN ('partner', 'banij')
                ),
                ADD CONSTRAINT order_returns_inspection_outcome_known CHECK (
                    inspection_outcome IS NULL OR inspection_outcome IN ('approved', 'rejected')
                ),
                -- A new case always says who is responsible; only legacy rows may not.
                ADD CONSTRAINT order_returns_v2_has_responsibility CHECK (
                    workflow_version < 2 OR (responsible_party IS NOT NULL AND on_behalf_of IS NOT NULL AND initiated_by_type IS NOT NULL)
                ),
                ADD CONSTRAINT order_returns_charges_not_negative CHECK (
                    (original_delivery_charge IS NULL OR original_delivery_charge >= 0)
                    AND (return_charge IS NULL OR return_charge >= 0)
                    AND (exchange_delivery_charge IS NULL OR exchange_delivery_charge >= 0)
                ),
                -- An inspection is a decision of a person, on a parcel that arrived.
                ADD CONSTRAINT order_returns_inspection_is_complete CHECK (
                    inspection_outcome IS NULL
                    OR (inspected_by IS NOT NULL AND inspected_at IS NOT NULL AND received_at IS NOT NULL)
                );

            -- A rejected inspection gives its claim on the order's lines back, like a
            -- rejected or cancelled return: the same units may be asked for again.
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
                  AND returns.status NOT IN ('rejected', 'cancelled', 'inspection_rejected');

                IF claimed + coalesce(NEW.approved_quantity, NEW.quantity) > sold THEN
                    RAISE EXCEPTION 'more of this line would come back than was sold'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        Schema::table('order_return_items', function (Blueprint $table) {
            $table->text('inspection_note')->nullable();
        });

        Schema::create('order_return_responsibility_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_return_id')->constrained('order_returns')->restrictOnDelete();
            $table->string('previous_party', 16)->nullable();
            $table->string('new_party', 16);
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->text('reason');

            $table->index(['order_return_id', 'id']);
        });

        Schema::create('order_return_charges', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_return_id')->constrained('order_returns')->restrictOnDelete();
            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();

            // What the money is for.
            $table->string('kind', 24);
            // 'charge' puts a cost on somebody; 'reversal' takes one back, and
            // points at the charge it answers.
            $table->string('entry_type', 12)->default('charge');
            $table->foreignId('reverses_charge_id')->nullable()->constrained('order_return_charges')->restrictOnDelete();

            // Who bears it. A Banij-borne charge is accounting only: no wallet.
            $table->string('bearer', 16);
            $table->decimal('amount', 19, 2);
            $table->char('currency_code', 3)->default('BDT');

            // recorded: Banij accounting · posted: debited from the Partner's wallet ·
            // outstanding: owed, the wallet could not cover it · settled: an
            // outstanding charge later collected · reversed: answered by a reversal.
            $table->string('status', 16);

            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 120)->unique();
            $table->timestamp('posted_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['order_return_id', 'id']);
            $table->index(['business_account_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_return_responsibility_changes
                ADD CONSTRAINT order_return_responsibility_parties_known CHECK (
                    new_party IN ('partner', 'banij') AND (previous_party IS NULL OR previous_party IN ('partner', 'banij'))
                ),
                ADD CONSTRAINT order_return_responsibility_is_a_change CHECK (previous_party IS DISTINCT FROM new_party),
                ADD CONSTRAINT order_return_responsibility_has_a_reason CHECK (length(btrim(reason)) > 0);

            ALTER TABLE order_return_charges
                ADD CONSTRAINT order_return_charges_kind_known CHECK (
                    kind IN ('return_charge', 'exchange_delivery', 'damage_loss', 'damage_delivery')
                ),
                ADD CONSTRAINT order_return_charges_entry_type_known CHECK (entry_type IN ('charge', 'reversal')),
                ADD CONSTRAINT order_return_charges_bearer_known CHECK (bearer IN ('partner', 'banij')),
                ADD CONSTRAINT order_return_charges_status_known CHECK (
                    status IN ('recorded', 'posted', 'outstanding', 'settled', 'reversed')
                ),
                ADD CONSTRAINT order_return_charges_amount_is_positive CHECK (amount > 0),
                ADD CONSTRAINT order_return_charges_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT order_return_charges_reason_present CHECK (length(btrim(reason)) > 0),
                -- A reversal answers a charge; a charge answers nothing.
                ADD CONSTRAINT order_return_charges_reversal_points_at_a_charge CHECK (
                    (entry_type = 'reversal') = (reverses_charge_id IS NOT NULL)
                ),
                -- A wallet transaction exists exactly when money moved in the wallet.
                ADD CONSTRAINT order_return_charges_wallet_matches_status CHECK (
                    (bearer = 'banij' AND wallet_transaction_id IS NULL)
                    OR (status IN ('posted', 'settled') AND wallet_transaction_id IS NOT NULL)
                    OR (status IN ('outstanding', 'reversed', 'recorded'))
                );

            -- What a charge *is* is fixed when it is written. Only its state moves
            -- (outstanding → settled, anything → reversed) and the wallet entry that
            -- explains it.
            CREATE TRIGGER order_return_charges_locked_columns
                BEFORE UPDATE OF public_id, order_return_id, business_account_id, kind, entry_type,
                                 reverses_charge_id, bearer, amount, currency_code, reason, idempotency_key
                ON order_return_charges
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'order_return_id', 'business_account_id', 'kind', 'entry_type',
                    'reverses_charge_id', 'bearer', 'amount', 'currency_code', 'reason', 'idempotency_key'
                );

            CREATE OR REPLACE FUNCTION feriwala_return_records_are_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is never deleted: a correction is a new row that points at what it answers', TG_TABLE_NAME
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_return_charges_no_delete
                BEFORE DELETE ON order_return_charges
                FOR EACH ROW EXECUTE FUNCTION feriwala_return_records_are_never_deleted();

            -- Attribution is history: it is never rewritten, only added to.
            CREATE TRIGGER order_return_responsibility_changes_no_update
                BEFORE UPDATE ON order_return_responsibility_changes
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
            CREATE TRIGGER order_return_responsibility_changes_no_delete
                BEFORE DELETE ON order_return_responsibility_changes
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS order_return_responsibility_changes_no_delete ON order_return_responsibility_changes;
            DROP TRIGGER IF EXISTS order_return_responsibility_changes_no_update ON order_return_responsibility_changes;
            DROP TRIGGER IF EXISTS order_return_charges_no_delete ON order_return_charges;
            DROP TRIGGER IF EXISTS order_return_charges_locked_columns ON order_return_charges;
            DROP FUNCTION IF EXISTS feriwala_return_records_are_never_deleted();
        SQL);

        Schema::dropIfExists('order_return_charges');
        Schema::dropIfExists('order_return_responsibility_changes');

        Schema::table('order_return_items', function (Blueprint $table) {
            $table->dropColumn('inspection_note');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_returns
                DROP CONSTRAINT IF EXISTS order_returns_inspection_is_complete,
                DROP CONSTRAINT IF EXISTS order_returns_charges_not_negative,
                DROP CONSTRAINT IF EXISTS order_returns_v2_has_responsibility,
                DROP CONSTRAINT IF EXISTS order_returns_inspection_outcome_known,
                DROP CONSTRAINT IF EXISTS order_returns_responsible_party_known,
                DROP CONSTRAINT IF EXISTS order_returns_on_behalf_of_known,
                DROP CONSTRAINT IF EXISTS order_returns_initiator_known,
                DROP CONSTRAINT IF EXISTS order_returns_case_type_known,
                DROP CONSTRAINT IF EXISTS order_returns_workflow_known;
        SQL);

        Schema::table('order_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inspected_by');
            $table->dropColumn([
                'workflow_version', 'case_type', 'initiated_by_type', 'on_behalf_of', 'responsible_party',
                'inspected_at', 'inspection_outcome', 'inspection_note',
                'original_delivery_charge', 'return_charge', 'exchange_delivery_charge',
                'other_adjustments', 'adjustment_note', 'completed_at',
            ]);
        });
    }
};
