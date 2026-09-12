<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every state a wallet transaction has ever been in (§23.3).
 *
 * §23.1 names no "reserve" and no "hold": every transaction type there is a
 * credit or a debit. §23.3 makes *Pending* and *On Hold* **statuses** a
 * transaction passes through. So a reservation is not a movement of value and
 * writes no ledger entry — it is a transaction sitting in a status, and the
 * money is still in the wallet, merely spoken for.
 *
 * That leaves a hole this table fills. `wallet_transactions.status` is a single
 * mutable column: it says where a claim is **now** and nothing about how it got
 * there. Release a reservation and the evidence that it was ever reserved is
 * overwritten — no previous state, no time of the change, no actor. For money
 * that is not good enough, and §23.2's demand for an immutable record of every
 * financial act does not stop being reasonable because no value crossed the
 * wallet's edge.
 *
 * So each transition is appended here, once, and never changed: what it moved
 * between, which bucket, what that bucket and the total held before and after,
 * who did it, why, under what idempotency identity, and — where value did move —
 * the ledger entry it produced. The ledger stays what it is: value entering and
 * leaving. This is the lifecycle beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transaction_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_transaction_id')->constrained()->cascadeOnDelete();

            /*
             * Denormalised on purpose. "What was reserved against this wallet
             * last March" is the question this table exists to answer, and it
             * should not need a join to a row whose status has since moved on.
             */
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();

            // Null on the opening event: nothing preceded it.
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);

            /*
             * Which bucket moved, where one did. Null for a plain posting, whose
             * effect is on the total and is explained by its ledger entry.
             */
            $table->string('bucket', 32)->nullable();

            $table->bigInteger('amount_minor');
            $table->string('currency_code', 3)->default('BDT');

            // Previous and resulting state, for the bucket and for the total.
            $table->bigInteger('bucket_before_minor')->nullable();
            $table->bigInteger('bucket_after_minor')->nullable();
            $table->bigInteger('total_before_minor');
            $table->bigInteger('total_after_minor');

            $table->string('source', 60);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();

            /*
             * The identity of the command behind it. Not unique here — one
             * command legitimately produces an opening event and a closing one —
             * but it is what ties an event back to the request that caused it.
             */
            $table->string('idempotency_key', 191)->nullable();

            /*
             * Where value actually moved, the entry that recorded it (§23.2).
             *
             * `restrictOnDelete`, not `nullOnDelete`: nulling it would be a
             * cascade quietly *updating* a row this table promises never
             * changes. The ledger forbids deletion anyway, so the restriction
             * costs nothing and states which of the two rules wins if it ever
             * comes up.
             */
            $table->foreignId('ledger_entry_id')->nullable()
                ->constrained('ledger_entries')->restrictOnDelete();

            $table->timestamp('occurred_at');

            $table->index(['wallet_id', 'occurred_at']);
            $table->index(['wallet_transaction_id', 'id']);
            $table->index('idempotency_key');
        });

        /*
         * Append-only in the database, for the reason the ledger is: a model
         * guard protects what goes through Eloquent and says nothing about a
         * migration, a console command or a psql prompt.
         *
         * Its own function rather than the ledger's, so the message names the
         * table the writer actually touched.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_table_is_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    '% is append-only (requirements.txt 23.2, 23.3): % is not permitted.',
                    TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER wallet_transaction_events_no_update
                BEFORE UPDATE ON wallet_transaction_events
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER wallet_transaction_events_no_delete
                BEFORE DELETE ON wallet_transaction_events
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS wallet_transaction_events_no_update ON wallet_transaction_events;
            DROP TRIGGER IF EXISTS wallet_transaction_events_no_delete ON wallet_transaction_events;
            DROP FUNCTION IF EXISTS feriwala_table_is_append_only();
        SQL);

        Schema::dropIfExists('wallet_transaction_events');
    }
};
