<?php

use App\Domain\Wallet\Models\Wallet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One wallet per active business account (§23, §24.2).
 *
 * §23 opens with "Every Active Account will have a Wallet and Financial Ledger",
 * so the wallet belongs to the **business**, not the person — the same rule the
 * rest of the commercial model follows (D1, D23). A unique index on
 * `business_account_id` holds it to one.
 *
 * **Only the primitive buckets are stored.** §24.2 asks the wallet to
 * distinguish usable service balance and available withdrawal balance, and both
 * are arithmetic over the columns below rather than columns of their own.
 * Storing a derived total is how a wallet comes to disagree with itself: two
 * writers, one of them forgetting, and no way to tell afterwards which was
 * right. {@see Wallet} is the one place those
 * definitions live.
 *
 * Every amount is BIGINT minor units with an explicit currency (D4). No balance
 * on this table may be written except by the ledger posting service, which
 * writes the row and its immutable entry in one transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // One wallet, one business. Unique rather than merely indexed: two
            // wallets for one account is a bug that would split its money.
            $table->foreignId('business_account_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('currency_code', 3)->default('BDT');

            /*
             * Everything the account has in the wallet. Every other bucket
             * below is a claim *against* this figure rather than an addition to
             * it, so the buckets can never sum to more than the money present.
             */
            $table->bigInteger('total_minor')->default(0);

            // The deposit §24 requires the account to maintain. Held here as an
            // amount; the rules that decide it belong to P2-11.
            $table->bigInteger('required_deposit_minor')->default(0);

            // Not spendable and not withdrawable — the minimum balance and any
            // explicit reservation against a pending charge (§24.2).
            $table->bigInteger('reserved_minor')->default(0);

            // Credited but not yet usable: awaiting settlement or clearance.
            $table->bigInteger('pending_minor')->default(0);

            // Frozen while something is under review (§23.3).
            $table->bigInteger('hold_minor')->default(0);

            // Collected by a courier and owed to the account, not yet settled
            // into the wallet. Reported beside the balance, never part of it.
            $table->bigInteger('cod_receivable_minor')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
