<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What was taken away because the balance fell short, and when (§24.3).
 *
 * Every graded action gets a row: which stage, when it began, what the account's
 * status was beforehand, and — once the money is back — when it was lifted and
 * why.
 *
 * The previous status is the important column. §24.3 says services are restored
 * after a sufficient top-up, and "restored" means *back to where it was*, not
 * "set to active". An account that was already restricted for a failed KYC
 * review must not be handed a working panel because it paid its deposit; the
 * only way to know that is to have written down where it came from.
 *
 * `cause` is what keeps restoration honest. Only rows caused by this balance
 * rule are ever lifted by it — a restriction placed by an administrator, by KYC,
 * or by a suspension is not this module's to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_restrictions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();

            $table->string('stage', 40);

            // Always `low_balance` today. Named rather than assumed, because the
            // day something else restricts an account this column is what stops
            // the balance sweep lifting it.
            $table->string('cause', 40)->default('low_balance');

            /*
             * Where the account was before this stage moved it, so restoration
             * can put it back rather than guessing at "active".
             */
            $table->string('previous_account_status', 40)->nullable();
            $table->string('applied_account_status', 40)->nullable();

            $table->text('reason')->nullable();
            $table->timestamp('started_at');

            // Null while it stands. The pair is what makes "is this account
            // restricted right now" a query rather than a calculation.
            $table->timestamp('lifted_at')->nullable();
            $table->text('lifted_reason')->nullable();

            $table->timestamps();

            $table->index(['wallet_id', 'stage', 'lifted_at']);
            $table->index(['business_account_id', 'lifted_at']);
        });

        /*
         * One **live** row per stage per wallet. Re-running enforcement must not
         * restrict the same account twice, and a partial unique index is the
         * only place that can be guaranteed rather than remembered.
         *
         * Partial rather than a plain unique across four columns: in Postgres
         * two nulls are distinct, so an index including `lifted_at` would let
         * every duplicate through precisely when it matters — while the
         * restriction is standing. Lifted rows are deliberately unconstrained,
         * because an account can fall short, recover, and fall short again.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX wallet_restrictions_live
                ON wallet_restrictions (wallet_id, stage, cause)
                WHERE lifted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_restrictions');
    }
};
