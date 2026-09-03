<?php

use App\Domain\Account\Actions\EvaluateActivationReadiness;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the moment an account became ready for approval (§5.1, §44).
 *
 * The approval queue is worked oldest-first, and "oldest" has to mean *waiting
 * longest for us* — not registered longest ago. Without this column someone who
 * registered in March and paid this morning sorts above someone who completed
 * everything last week, which inverts the queue exactly where it matters.
 *
 * Nullable and not derived: it is stamped once, by
 * {@see EvaluateActivationReadiness}, when the last
 * outstanding requirement is met — and cleared if a requirement is later
 * reversed, so an account that falls out of the queue does not keep a readiness
 * time it no longer has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('approval_pending_at')->nullable()->after('activated_at');

            // The queue's sort column, on a table that will hold every account.
            $table->index('approval_pending_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['approval_pending_at']);
            $table->dropColumn('approval_pending_at');
        });
    }
};
