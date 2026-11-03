<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether Banij's frozen receivable on a Partner-gateway order has been collected
 * from the Partner's Wallet (D36).
 *
 * The receivable itself is append-only and never moves; this is the one record
 * that does. It is the receivable's own status, kept apart from the payment's: the
 * customer's payment stays `paid` because the Partner received that money, and
 * what Banij is owed is a different fact with its own life.
 *
 *   pending_collection ─┬─> collected ──> partially_reversed ──> reversed
 *                       │                 └────────────────────> reversed
 *                       ├─> wallet_insufficient ─┬─> collected
 *                       │                        ├─> reservation_recovery_required
 *                       │                        └─> deadline_expired_refund_required
 *                       ├─> reservation_recovery_required ─┬─> collected
 *                       │                                  ├─> wallet_insufficient
 *                       │                                  └─> deadline_expired_refund_required
 *                       └─> deadline_expired_refund_required
 *
 * The two reversal states are reserved for the later approved reversal flow;
 * nothing writes them yet.
 *
 * What is frozen when the row is made — the receivable, the order, the payment,
 * the amount, when the payment was verified and the deadline that follows from it
 * — can never change, so a later change to the deadline setting reaches only the
 * holds that come after it. The collection fact is written once.
 *
 * `order_hold_reservations` records the stock reservations made for an order
 * **after** its first one lapsed. A reservation never returns from released or
 * expired, and its order line cannot be repointed, so a replacement is a new
 * reservation, linked here and never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_receivable_collections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_platform_receivable_id')
                ->unique('order_receivable_collections_receivable_unique')
                ->constrained('order_platform_receivables', indexName: 'order_receivable_collections_receivable_foreign')
                ->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('business_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->decimal('amount', 19, 2);

            $table->string('status', 40)->default('pending_collection');
            $table->string('status_reason', 64)->nullable();

            $table->timestamp('verified_at');
            $table->unsignedSmallInteger('deadline_hours');
            $table->timestamp('deadline_at');

            $table->timestamp('collected_at')->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('wallet_insufficient_at')->nullable();
            $table->timestamp('recovery_required_at')->nullable();
            $table->timestamp('deadline_expired_at')->nullable();

            $table->timestamps();

            // Withdrawals ask "does this account have a live hold", top-ups walk the oldest first.
            $table->index(['business_account_id', 'status', 'verified_at'], 'order_receivable_collections_account_queue');
            $table->index(['status', 'deadline_at'], 'order_receivable_collections_deadline_sweep');
        });

        Schema::create('order_hold_reservations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_receivable_collection_id')
                ->constrained('order_receivable_collections', indexName: 'order_hold_reservations_collection_foreign')
                ->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->string('purpose', 24);
            $table->unsignedSmallInteger('sequence');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['order_receivable_collection_id', 'order_item_id', 'sequence'], 'order_hold_reservations_line_sequence_unique');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_receivable_collections
                ADD CONSTRAINT order_receivable_collections_status_known CHECK (status IN (
                    'pending_collection', 'collected', 'wallet_insufficient', 'reservation_recovery_required',
                    'deadline_expired_refund_required', 'partially_reversed', 'reversed'
                )),
                ADD CONSTRAINT order_receivable_collections_amount_not_negative CHECK (amount >= 0),
                ADD CONSTRAINT order_receivable_collections_deadline_follows_verification CHECK (deadline_at >= verified_at),
                -- Collected, or reversed after having been, tells the same story as its facts.
                ADD CONSTRAINT order_receivable_collections_collection_fact CHECK (
                    (status IN ('collected', 'partially_reversed', 'reversed')) = (collected_at IS NOT NULL)
                ),
                -- A receivable of nothing has nothing to debit, so it has no Wallet transaction.
                ADD CONSTRAINT order_receivable_collections_collected_has_its_debit CHECK (
                    collected_at IS NULL OR amount = 0 OR wallet_transaction_id IS NOT NULL
                ),
                ADD CONSTRAINT order_receivable_collections_uncollected_has_no_debit CHECK (
                    collected_at IS NOT NULL OR wallet_transaction_id IS NULL
                ),
                ADD CONSTRAINT order_receivable_collections_expiry_is_stamped CHECK (
                    (status = 'deadline_expired_refund_required') = (deadline_expired_at IS NOT NULL)
                );

            ALTER TABLE order_hold_reservations
                ADD CONSTRAINT order_hold_reservations_purpose_known CHECK (purpose IN ('recovery')),
                ADD CONSTRAINT order_hold_reservations_sequence_positive CHECK (sequence >= 1);

            CREATE OR REPLACE FUNCTION feriwala_receivable_collection_move_is_allowed()
            RETURNS trigger LANGUAGE plpgsql AS $function$
            BEGIN
                IF NEW.status IS NOT DISTINCT FROM OLD.status THEN
                    RETURN NEW;
                END IF;

                IF NOT (
                    (OLD.status = 'pending_collection' AND NEW.status IN (
                        'collected', 'wallet_insufficient', 'reservation_recovery_required', 'deadline_expired_refund_required'))
                    OR (OLD.status = 'wallet_insufficient' AND NEW.status IN (
                        'collected', 'reservation_recovery_required', 'deadline_expired_refund_required'))
                    OR (OLD.status = 'reservation_recovery_required' AND NEW.status IN (
                        'collected', 'wallet_insufficient', 'deadline_expired_refund_required'))
                    OR (OLD.status = 'collected' AND NEW.status IN ('partially_reversed', 'reversed'))
                    OR (OLD.status = 'partially_reversed' AND NEW.status = 'reversed')
                ) THEN
                    RAISE EXCEPTION 'order_receivable_collections % cannot move from % to %', OLD.id, OLD.status, NEW.status
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $function$;

            CREATE OR REPLACE FUNCTION feriwala_receivable_collection_fact_is_set_once()
            RETURNS trigger LANGUAGE plpgsql AS $function$
            BEGIN
                IF (OLD.collected_at IS NOT NULL AND NEW.collected_at IS DISTINCT FROM OLD.collected_at)
                    OR (OLD.wallet_transaction_id IS NOT NULL AND NEW.wallet_transaction_id IS DISTINCT FROM OLD.wallet_transaction_id)
                    OR (OLD.deadline_expired_at IS NOT NULL AND NEW.deadline_expired_at IS DISTINCT FROM OLD.deadline_expired_at)
                THEN
                    RAISE EXCEPTION 'order_receivable_collections % collection fact cannot be changed once written', OLD.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $function$;

            CREATE TRIGGER order_receivable_collections_frozen_columns
                BEFORE UPDATE OF public_id, order_platform_receivable_id, order_id, business_account_id, payment_id,
                    currency_code, amount, verified_at, deadline_hours, deadline_at
                ON order_receivable_collections
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'order_platform_receivable_id', 'order_id', 'business_account_id', 'payment_id',
                    'currency_code', 'amount', 'verified_at', 'deadline_hours', 'deadline_at'
                );
            CREATE TRIGGER order_receivable_collections_legal_move
                BEFORE UPDATE OF status ON order_receivable_collections
                FOR EACH ROW EXECUTE FUNCTION feriwala_receivable_collection_move_is_allowed();
            CREATE TRIGGER order_receivable_collections_fact_set_once
                BEFORE UPDATE OF collected_at, wallet_transaction_id, deadline_expired_at ON order_receivable_collections
                FOR EACH ROW EXECUTE FUNCTION feriwala_receivable_collection_fact_is_set_once();
            CREATE TRIGGER order_receivable_collections_no_delete
                BEFORE DELETE ON order_receivable_collections
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER order_hold_reservations_no_update
                BEFORE UPDATE ON order_hold_reservations
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
            CREATE TRIGGER order_hold_reservations_no_delete
                BEFORE DELETE ON order_hold_reservations
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS order_hold_reservations_no_update ON order_hold_reservations;
            DROP TRIGGER IF EXISTS order_hold_reservations_no_delete ON order_hold_reservations;
            DROP TRIGGER IF EXISTS order_receivable_collections_frozen_columns ON order_receivable_collections;
            DROP TRIGGER IF EXISTS order_receivable_collections_legal_move ON order_receivable_collections;
            DROP TRIGGER IF EXISTS order_receivable_collections_fact_set_once ON order_receivable_collections;
            DROP TRIGGER IF EXISTS order_receivable_collections_no_delete ON order_receivable_collections;
        SQL);

        Schema::dropIfExists('order_hold_reservations');
        Schema::dropIfExists('order_receivable_collections');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS feriwala_receivable_collection_move_is_allowed();
            DROP FUNCTION IF EXISTS feriwala_receivable_collection_fact_is_set_once();
        SQL);
    }
};
