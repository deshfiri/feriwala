<?php

use App\Domain\Account\Enums\AccountRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff invitations against a business account (D1, §8.1).
 *
 * Replaces the starter kit's team invitations. The user-facing vocabulary is
 * Account, Staff and Permissions, and the team concept must not survive in a
 * table name any more than in a route.
 *
 * The interesting constraints are the two partial unique indexes. A plain
 * unique on `(business_account_id, email)` would stop somebody being re-invited
 * after they declined or left, which is a normal thing to want. Partial indexes
 * scoped to invitations that are still live say what is actually meant: **one
 * open invitation per address per account**, and no second membership for
 * someone already inside.
 *
 * Postgres-specific, and deliberately so (D5 pins PostgreSQL). Enforcing this
 * in application code instead would leave a race between two managers inviting
 * the same person, and the whole point of a limit is that it cannot be
 * overbooked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_invitations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();

            // Who it is for. Email is the identity an acceptance must match;
            // mobile is optional and, when set, must match too (§6).
            $table->string('email');
            $table->string('mobile', 20)->nullable();

            $table->string('role', 20)->default(AccountRole::Staff->value);

            // Single-use, unguessable, and not the id. A sequential id in an
            // invitation link is an invitation to walk the range.
            $table->string('token', 64)->unique();

            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The staff-limit query: live invitations for this account (§37).
            $table->index(['business_account_id', 'accepted_at', 'revoked_at']);
            $table->index('expires_at');
        });

        // One live invitation per address per account. Declining, revoking or
        // letting one expire frees the address to be invited again.
        DB::statement('
            CREATE UNIQUE INDEX account_invitations_open_unique
            ON account_invitations (business_account_id, lower(email))
            WHERE accepted_at IS NULL AND revoked_at IS NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitations');
    }
};
