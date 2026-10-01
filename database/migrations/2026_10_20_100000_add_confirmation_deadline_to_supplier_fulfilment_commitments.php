<?php

use App\Domain\Supplier\Actions\RecordSupplierFulfilmentCommitment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a Supplier's fulfilment commitment carry a confirmation deadline and
 * their own expected-ready date (Advanced Order Management batch, Commit 2).
 *
 * `confirmation_due_at` is computed once, at creation
 * ({@see RecordSupplierFulfilmentCommitment::forAllocation()}),
 * from `config('supplier.fulfilment_confirmation_deadline_hours')` — a later
 * change to that setting never rewrites an already-outstanding commitment's
 * own deadline. `expected_ready_at` is the Supplier's own answer, captured at
 * confirmation.
 *
 * Nullable and additive: every existing row leaves both null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_fulfilment_commitments', function (Blueprint $table) {
            $table->timestamp('confirmation_due_at')->nullable()->after('due_at');
            $table->timestamp('expected_ready_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_fulfilment_commitments', function (Blueprint $table) {
            $table->dropColumn(['confirmation_due_at', 'expected_ready_at']);
        });
    }
};
