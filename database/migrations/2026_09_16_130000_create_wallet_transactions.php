<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lifecycle half of a wallet movement (§23.3).
 *
 * §23.3 gives a wallet transaction twelve statuses to move through, and §23.2
 * makes a ledger entry immutable. Both cannot be true of one row, so they are
 * two: **this** is the thing that happens over time — initiated, held, reviewed,
 * approved, settled — and a ledger entry is the immutable record of what it did
 * to the balance at the moment it did it.
 *
 * A transaction that never realises posts nothing. One that does posts exactly
 * one entry, and the entry carries the status it was posted under, frozen.
 *
 * `idempotency_key` is unique here as well as on the ledger. The key belongs to
 * the **command** — "credit this wallet for that payment" — and refusing the
 * second attempt at the envelope is what stops a duplicate ever reaching the
 * posting at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();

            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 40);
            $table->string('direction', 10);
            $table->string('source', 40);

            $table->bigInteger('amount_minor');
            $table->string('currency_code', 3)->default('BDT');

            $table->string('status', 32);

            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description');
            $table->text('internal_note')->nullable();

            // Why, for anything that puts an earlier entry right (§23.2).
            $table->text('reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('idempotency_key', 191)->nullable()->unique();

            $table->timestamps();

            $table->index(['wallet_id', 'id']);
            $table->index(['business_account_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        // The ledger's link to its envelope, now that the envelope exists.
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreign('wallet_transaction_id')
                ->references('id')->on('wallet_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['wallet_transaction_id']);
        });

        Schema::dropIfExists('wallet_transactions');
    }
};
