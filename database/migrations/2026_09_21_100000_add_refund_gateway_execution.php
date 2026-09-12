<?php

use App\Domain\Billing\Actions\DecideRefund;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The execution half of a refund (§26.3, D17).
 *
 * `refund_requests` already records the decision: who asked, for which
 * component, how much, who approved it and why. What it has never held is what
 * happened when the money was actually sent back — because, as
 * {@see DecideRefund} says, an approval moves no
 * money on its own and the ledger did not exist when that was written.
 *
 * These are the columns that make an approved decision executable against a
 * provider, and they carry the same two unique indexes the payments table does,
 * for the same reasons.
 *
 * `idempotency_key` is what stops a double-click, a retried request, or two
 * administrators at once becoming two refunds of the same money. It is decided
 * before the provider is contacted, so the guard exists whether or not the
 * provider offers one of its own.
 *
 * `gateway_refund_reference` is the provider's own refund identity, and one of
 * those belongs to exactly one row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            /*
             * Copied from the payment when the refund is sent rather than
             * joined for. A refund is answered by the provider that took the
             * money, and which provider that was is a fact about this refund.
             */
            $table->string('gateway', 40)->nullable()->after('refundability');

            $table->string('idempotency_key', 128)->nullable()->unique()->after('gateway');
            $table->string('gateway_refund_reference')->nullable()->unique()->after('idempotency_key');

            $table->timestamp('submitted_at')->nullable()->after('decided_at');
            $table->timestamp('processed_at')->nullable()->after('submitted_at');
            $table->text('failure_reason')->nullable()->after('decision_note');

            /*
             * The posting that took the money back out of the wallet, when the
             * original payment had put it in. Null for everything else — most
             * payments are money paid *to* Feriwala, and refunding one reverses
             * nothing in a wallet.
             */
            $table->foreignId('wallet_transaction_id')
                ->nullable()
                ->after('failure_reason')
                ->constrained('wallet_transactions')
                ->nullOnDelete();

            // What the provider actually said, redacted on the way in (§42).
            $table->json('evidence')->nullable()->after('wallet_transaction_id');
        });

        /*
         * Refunds become undeletable now that money moves on them.
         *
         * The original table already intended this — "a rejection is kept, not
         * deleted" — but a comment is not a control, and how much of a payment
         * has been refunded is now computed from these rows. A row somebody can
         * remove is a way of making refunded money disappear from the record.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_refund_request_no_delete()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'A refund request cannot be deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER refund_requests_no_delete
                BEFORE DELETE ON refund_requests
                FOR EACH ROW EXECUTE FUNCTION feriwala_refund_request_no_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS refund_requests_no_delete ON refund_requests');
        DB::unprepared('DROP FUNCTION IF EXISTS feriwala_refund_request_no_delete()');

        Schema::table('refund_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wallet_transaction_id');
            $table->dropUnique(['idempotency_key']);
            $table->dropUnique(['gateway_refund_reference']);

            $table->dropColumn([
                'gateway',
                'idempotency_key',
                'gateway_refund_reference',
                'submitted_at',
                'processed_at',
                'failure_reason',
                'evidence',
            ]);
        });
    }
};
