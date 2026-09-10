<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The financial ledger (§23.2).
 *
 * "Every financial transaction must create an immutable Ledger Entry" — this is
 * that entry, with the full column set §23.2 lists. It is the record of what
 * happened to money, and it is written once.
 *
 * **Balance before and after are stored, not derived.** Re-deriving a historical
 * balance means replaying every entry since the wallet opened and trusting that
 * none was ever missed; storing both ends of every move means any single row can
 * be checked against its neighbours, which is what makes the P2-9 integrity
 * sweep possible at all.
 *
 * The four bucket snapshots — pending, reserved, available, hold — record what
 * the wallet looked like immediately after this entry. §23.2 asks for them, and
 * they are what lets a statement from last March be read without reconstructing
 * March.
 *
 * Related-entity columns exist for modules that are not built yet. A foreign key
 * is declared where the table exists and a plain identifier where it does not,
 * rather than leaving the column out: a ledger that had to be migrated every
 * time a module landed would be a ledger whose history gets renumbered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // §23.2's "Unique Transaction Reference".
            $table->string('reference', 32)->unique();

            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();

            // §23.2's "User" — the person the entry is about, where there is
            // one. Platform-initiated entries have an account and no user.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 40);

            // Where the entry came from: 'payment', 'manual', 'system', 'order'.
            // Coarser than the related ids below, and readable on its own.
            $table->string('source', 40);

            /*
             * §23.2's related entities. Real foreign keys where the table
             * exists today; plain identifiers where the owning module is still
             * to come, so the column is ready and the constraint arrives with
             * the table it points at.
             */
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_package_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('withdrawal_id')->nullable();
            $table->unsignedBigInteger('website_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedBigInteger('commission_id')->nullable();
            $table->unsignedBigInteger('referral_id')->nullable();

            /*
             * One of these is the amount and the other is zero. Both are stored
             * positive: a debit is identified by which column it lands in, so
             * summing "total debited this month" needs no sign handling and a
             * misplaced minus cannot silently become a credit.
             */
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->string('currency_code', 3)->default('BDT');

            $table->bigInteger('balance_before_minor');
            $table->bigInteger('balance_after_minor');

            // What every bucket held immediately after this entry (§23.2).
            $table->bigInteger('pending_minor')->default(0);
            $table->bigInteger('reserved_minor')->default(0);
            $table->bigInteger('available_minor')->default(0);
            $table->bigInteger('hold_minor')->default(0);

            // The transaction status at the moment of posting, frozen. The
            // entry never changes again, so this is a snapshot rather than a
            // lifecycle — the lifecycle lives on `wallet_transactions`.
            $table->string('status', 32);

            // The lifecycle object this posting belongs to. The constraint
            // arrives with `wallet_transactions` in P2-7.
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            // What the account holder reads.
            $table->string('description');

            /*
             * §23.2's "Internal note" — staff only. Never sent to an account
             * member's screen; the wallet controllers select columns explicitly
             * rather than handing the model out whole.
             */
            $table->text('internal_note')->nullable();

            /*
             * The durable half of idempotency. The cache-backed service covers
             * the 24-hour replay window; this unique index is what still holds
             * a year later, and it is the reason a retried gateway callback
             * cannot credit a wallet twice (§36.1).
             */
            $table->string('idempotency_key', 191)->nullable()->unique();

            // A correction points at what it corrects (§23.2). The original is
            // never touched; this is how the two are read together.
            $table->foreignId('corrects_ledger_entry_id')->nullable()
                ->constrained('ledger_entries')->nullOnDelete();

            // No `updated_at`. Nothing updates.
            $table->timestamp('created_at');

            $table->index(['wallet_id', 'id']);
            $table->index(['business_account_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
