<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\UserStatus;
use App\Enums\TeamRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Splits the login identity from the commercial account (D1, §5, §6).
 *
 * `users` was carrying both: one status column answered "may this person sign
 * in" and "may this business trade" at once. That made a Feriwala staff member
 * complete commercial KYC and pay an activation fee before they could open the
 * admin panel, and would have made every invited staff member do the same to
 * join someone else's workspace.
 *
 * After this migration:
 *
 *   - `users.identity_status` — security and access. Gates everything.
 *   - `business_accounts.status` — the §5.3 commercial lifecycle. Gates trading.
 *
 * Everything commercial moves with it: KYC submissions, payments, package
 * subscriptions and the status history now belong to the account, not the
 * person. The starter kit's personal team becomes that account, so nothing is
 * orphaned and no user loses their place.
 *
 * The teams tables are left in place and untouched. P1-65 to P1-68 remove the
 * remaining URL and UI dependencies on them; dropping them in the same step as
 * a data move would mean one migration to unpick if either half proved wrong.
 */
return new class extends Migration
{
    /** Commercial tables that hang off the account rather than the person. */
    private const COMMERCIAL_TABLES = [
        'kyc_submissions',
        'payments',
        'user_packages',
    ];

    public function up(): void
    {
        $this->createAccountTables();
        $this->backfillAccountsFromTeams();
        $this->moveCommercialTables();
        $this->moveStatusHistory();
        $this->reduceUsersToIdentity();
    }

    private function createAccountTables(): void
    {
        Schema::create('business_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('slug')->unique();

            // One owner, one account (D1). The unique index is the rule, not a
            // convention someone has to remember.
            $table->foreignId('owner_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->string('status', 40)->default(AccountStatus::Registered->value);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('approval_pending_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('activated_at');
            $table->index('approval_pending_at');
        });

        Schema::create('business_account_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();

            // Unique across the whole table, not just per account: a person
            // belongs to one business (D1). Without this the account switcher
            // that P1-65 removes would be reachable again through the data.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('role');
            $table->timestamps();

            $table->index(['business_account_id', 'role']);
        });
    }

    /**
     * Every existing user becomes the owner of one account, carrying their
     * commercial status across unchanged.
     */
    private function backfillAccountsFromTeams(): void
    {
        $now = now();

        DB::table('users')->orderBy('id')->chunkById(200, function ($users) use ($now) {
            foreach ($users as $user) {
                // Prefer the name of the personal team they already had, so the
                // account keeps the label its owner has been seeing.
                $team = DB::table('teams')
                    ->join('team_members', 'teams.id', '=', 'team_members.team_id')
                    ->where('team_members.user_id', $user->id)
                    ->where('team_members.role', TeamRole::Owner->value)
                    ->orderBy('teams.id')
                    ->select('teams.name', 'teams.slug')
                    ->first();

                $accountId = DB::table('business_accounts')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'name' => $team->name ?? $user->name,
                    'slug' => $this->uniqueSlug($team->slug ?? $user->name, $user->id),
                    'owner_id' => $user->id,
                    'status' => $user->status,
                    'activated_at' => $user->activated_at,
                    'approval_pending_at' => $user->approval_pending_at,
                    'created_at' => $user->created_at ?? $now,
                    'updated_at' => $now,
                ]);

                DB::table('business_account_members')->insert([
                    'business_account_id' => $accountId,
                    'user_id' => $user->id,
                    'role' => TeamRole::Owner->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    private function uniqueSlug(string $from, int $userId): string
    {
        $base = Str::slug($from) ?: 'account';

        return DB::table('business_accounts')->where('slug', $base)->exists()
            ? $base.'-'.$userId
            : $base;
    }

    /**
     * Repoint the commercial tables from the person to the account.
     *
     * Written as add-backfill-drop rather than a rename: the foreign key has a
     * different target, and a rename would leave rows pointing at `users` under
     * a column called `business_account_id`.
     */
    private function moveCommercialTables(): void
    {
        foreach (self::COMMERCIAL_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('business_account_id')->nullable()->after('id')
                    ->constrained()->cascadeOnDelete();
            });

            DB::statement("
                UPDATE {$name}
                SET business_account_id = business_accounts.id
                FROM business_accounts
                WHERE business_accounts.owner_id = {$name}.user_id
            ");
        }

        // kyc_submissions carried a unique (user_id, round); the round is per
        // account now, and dropping the old index before the column keeps
        // PostgreSQL from refusing the drop.
        Schema::table('kyc_submissions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'round']);
        });

        foreach (self::COMMERCIAL_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });

            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('business_account_id')->nullable(false)->change();
            });
        }

        Schema::table('kyc_submissions', function (Blueprint $table) {
            $table->unique(['business_account_id', 'round']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['business_account_id', 'status']);
        });

        Schema::table('user_packages', function (Blueprint $table) {
            $table->index(['business_account_id', 'status']);
        });

        $this->moveCurrentSubscriptionPointer();
    }

    /**
     * The pointer to the live subscription is the account's, not the person's.
     *
     * An invited staff member does not have their own package — they work under
     * the one the account owner paid for — so leaving this on `users` would give
     * every staff member a second, always-empty subscription slot.
     */
    private function moveCurrentSubscriptionPointer(): void
    {
        Schema::table('business_accounts', function (Blueprint $table) {
            $table->foreignId('current_user_package_id')->nullable()
                ->constrained('user_packages')->nullOnDelete();
        });

        DB::statement('
            UPDATE business_accounts
            SET current_user_package_id = users.current_user_package_id
            FROM users
            WHERE users.id = business_accounts.owner_id
        ');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_user_package_id');
        });
    }

    /**
     * The status history becomes the account's rather than the user's.
     */
    private function moveStatusHistory(): void
    {
        // Columns first, rename last. PostgreSQL does not rename a table's
        // constraints along with the table, so dropping `user_id` after the
        // rename looks for `business_account_status_history_user_id_foreign`
        // while the constraint is still called `user_status_history_...`.
        Schema::table('user_status_history', function (Blueprint $table) {
            $table->foreignId('business_account_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
        });

        DB::statement('
            UPDATE user_status_history
            SET business_account_id = business_accounts.id
            FROM business_accounts
            WHERE business_accounts.owner_id = user_status_history.user_id
        ');

        Schema::table('user_status_history', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('user_status_history', function (Blueprint $table) {
            $table->foreignId('business_account_id')->nullable(false)->change();
            $table->index(['business_account_id', 'created_at']);
        });

        Schema::rename('user_status_history', 'business_account_status_history');
    }

    /**
     * `users` keeps only what identifies and secures a person.
     */
    private function reduceUsersToIdentity(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('identity_status', 20)
                ->default(UserStatus::Active->value)
                ->after('public_id');

            $table->timestamp('identity_status_changed_at')->nullable()->after('identity_status');
        });

        // An account that was suspended or closed commercially had its *person*
        // barred too, under the old single-status model. Carrying that across is
        // the safe reading: a suspension that silently became "identity active"
        // during a migration would hand access back to someone who had it taken
        // away.
        DB::table('users')
            ->whereIn('status', [AccountStatus::Suspended->value, AccountStatus::Closed->value])
            ->update([
                'identity_status' => DB::raw(
                    "CASE WHEN status = '".AccountStatus::Closed->value."' THEN '".UserStatus::Closed->value."'"
                    ." ELSE '".UserStatus::Suspended->value."' END"
                ),
                'identity_status_changed_at' => now(),
            ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['activated_at']);
            $table->dropIndex(['approval_pending_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status', 'activated_at', 'approval_pending_at']);
            $table->index('identity_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 40)->default(AccountStatus::Registered->value)->after('public_id');
            $table->timestamp('activated_at')->nullable()->after('status');
            $table->timestamp('approval_pending_at')->nullable()->after('activated_at');
        });

        DB::statement('
            UPDATE users
            SET status = business_accounts.status,
                activated_at = business_accounts.activated_at,
                approval_pending_at = business_accounts.approval_pending_at
            FROM business_accounts
            WHERE business_accounts.owner_id = users.id
        ');

        Schema::table('users', function (Blueprint $table) {
            $table->index('status');
            $table->index('activated_at');
            $table->index('approval_pending_at');
            $table->dropIndex(['identity_status']);
            $table->dropColumn(['identity_status', 'identity_status_changed_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_user_package_id')->nullable()
                ->constrained('user_packages')->nullOnDelete();
        });

        DB::statement('
            UPDATE users
            SET current_user_package_id = business_accounts.current_user_package_id
            FROM business_accounts
            WHERE business_accounts.owner_id = users.id
        ');

        Schema::rename('business_account_status_history', 'user_status_history');
        $this->restoreOwnership('user_status_history');

        Schema::table('kyc_submissions', function (Blueprint $table) {
            $table->dropUnique(['business_account_id', 'round']);
        });

        foreach (self::COMMERCIAL_TABLES as $name) {
            $this->restoreOwnership($name);
        }

        Schema::table('kyc_submissions', function (Blueprint $table) {
            $table->unique(['user_id', 'round']);
        });

        Schema::dropIfExists('business_account_members');
        Schema::dropIfExists('business_accounts');
    }

    private function restoreOwnership(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->foreignId('user_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
        });

        DB::statement("
            UPDATE {$table}
            SET user_id = business_accounts.owner_id
            FROM business_accounts
            WHERE business_accounts.id = {$table}.business_account_id
        ");

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropConstrainedForeignId('business_account_id');
            $blueprint->foreignId('user_id')->nullable(false)->change();
        });
    }
};
