<?php

use App\Domain\Billing\Enums\RefundStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refund decisions, one row per request (D17).
 *
 * D17 requires every refund decision to reach the financial ledger and the
 * audit log. This is the record the decision is *made* on: who asked, for what
 * component and how much, who decided, and what they said.
 *
 * A rejection is kept, not deleted. "We refused this in March and here is why"
 * is the answer a chargeback dispute needs, and a table holding only successful
 * refunds cannot give it.
 *
 * The amount is stored per component rather than as one total. §5.1 makes the
 * registration fee and the package fee separately reportable, and a refund that
 * collapsed them would be unreconcilable against the payment it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();

            // The component being refunded — a registration fee, a package fee.
            $table->string('allocation_type', 40);

            $table->bigInteger('amount_minor');
            $table->string('currency_code', 3);

            // The rule that was in force when the request was made, copied in.
            // A policy change later must not rewrite the basis on which a past
            // decision was taken.
            $table->string('refundability', 32);

            $table->string('status', 32)->default(RefundStatus::Requested->value);

            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['payment_id', 'status']);
            $table->index(['business_account_id', 'status']);
        });

        /*
         * One **open** request per component per payment.
         *
         * A partial unique index rather than an application check: two
         * administrators opening a refund on the same fee at the same moment
         * would both pass a check-then-insert, and the account would end up
         * owed the money twice. Once a request is decided it stops occupying
         * the slot, so a rejected refund can be asked for again.
         */
        DB::statement("
            CREATE UNIQUE INDEX refund_requests_open_unique
            ON refund_requests (payment_id, allocation_type)
            WHERE status = '".RefundStatus::Requested->value."'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};
