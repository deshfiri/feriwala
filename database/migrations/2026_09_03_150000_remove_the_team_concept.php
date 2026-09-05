<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the starter kit's teams (D1, §8.1, §32).
 *
 * P1-78 moved everything commercial onto `business_accounts` and left these
 * tables standing so a single migration could be unpicked if either half proved
 * wrong. Both halves held, so the teams go — along with `users.current_team_id`,
 * which was the switcher's memory and the reason a URL needed an account
 * segment at all.
 *
 * Nothing is carried across, because nothing was left: the personal team's name
 * became the account name in P1-78, and no team ever held a second member in
 * this application. Roles are re-spelled in the account vocabulary on the way
 * past, so a membership written under the old names still means what it meant.
 *
 * Irreversible by design. `down()` restores the shape but not the rows: putting
 * back an empty `teams` table is honest, and inventing teams to fill it would be
 * a fabrication dressed as a rollback.
 */
return new class extends Migration
{
    /** Old team role → account role. */
    private const ROLE_MAP = [
        'admin' => 'manager',
        'member' => 'staff',
    ];

    public function up(): void
    {
        foreach (self::ROLE_MAP as $from => $to) {
            DB::table('business_account_members')->where('role', $from)->update(['role' => $to]);
        }

        // The foreign key goes before the tables it points at.
        if (Schema::hasColumn('users', 'current_team_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('current_team_id');
            });
        }

        Schema::dropIfExists('team_invitations');
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }

    public function down(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_personal')->default(false);
            $table->timestamps();
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('code')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'email']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_team_id')->nullable()->constrained('teams')->nullOnDelete();
        });
    }
};
