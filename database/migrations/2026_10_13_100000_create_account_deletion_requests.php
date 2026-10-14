<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asking to have an account deleted.
 *
 * A person, a partner's staff member or a Supplier cannot delete their own
 * account; they ask, and an authorised member of staff decides. One request is
 * open per person at a time.
 *
 * Approving a request erases the account (see EraseAccount): the login stays as
 * an anonymous tombstone, so the request stays with it, marked approved, and the
 * final audit line — which holds no personal data — records who did it and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // Exactly one of these names who is asking (enforced below).
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->cascadeOnDelete();

            $table->string('status', 16)->default('pending');
            $table->text('reason');
            $table->timestamp('requested_at');

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['status', 'requested_at']);
        });

        DB::statement("ALTER TABLE account_deletion_requests ADD CONSTRAINT account_deletion_requests_status_known CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn'))");
        DB::statement('ALTER TABLE account_deletion_requests ADD CONSTRAINT account_deletion_requests_one_subject CHECK ((user_id IS NOT NULL)::int + (supplier_id IS NOT NULL)::int = 1)');

        // One open request per person: asking twice is the same ask.
        DB::statement("CREATE UNIQUE INDEX account_deletion_requests_one_pending ON account_deletion_requests (COALESCE(user_id, 0), COALESCE(supplier_id, 0)) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
    }
};
