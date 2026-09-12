<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What this wallet was actually required to hold, and when it was decided
 * (§24.1, §24.2).
 *
 * A deposit rule is a policy; an obligation is what one account is held to. They
 * have to be separate, because a rule edited in June must not change what an
 * account was required to hold in March — and an account restricted in March was
 * restricted against the figures of the day, not against today's.
 *
 * So the resolved rule is **captured**: its figures are copied onto the wallet
 * when the obligation is taken on, and the copy is what every later check reads.
 * `wallets` carries the current obligation because that is what a screen and a
 * posting need; `wallet_deposit_obligations` keeps every capture, so the history
 * is reproducible without replaying the rule table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            /*
             * §24.2 distinguishes the reserved minimum balance from the required
             * deposit, and they are not the same promise: a deposit may be
             * spendable on services (§24.4) while a minimum balance is, by
             * definition, the part that has to stay.
             */
            $table->bigInteger('minimum_balance_minor')->default(0)->after('required_deposit_minor');

            /*
             * §24.4's "usable for service charges", captured with the rest. The
             * default is true because a deposit that cannot be spent is the
             * stricter reading, and nobody has chosen it yet.
             */
            $table->boolean('deposit_usable_for_charges')->default(true);

            // Which rule this obligation came from, and when it was taken on.
            $table->foreignId('deposit_rule_id')->nullable()
                ->constrained('deposit_rules')->nullOnDelete();
            $table->timestamp('obligation_captured_at')->nullable();

            // When the deposit is due, from §24.1's deadline in days.
            $table->timestamp('deposit_due_at')->nullable();
        });

        Schema::create('wallet_deposit_obligations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();

            /*
             * Null when no rule applied: "nothing is required of this account"
             * is an obligation worth recording, because it is the answer to why
             * nothing was enforced.
             */
            $table->foreignId('deposit_rule_id')->nullable()
                ->constrained('deposit_rules')->restrictOnDelete();

            $table->bigInteger('required_deposit_minor');
            $table->bigInteger('minimum_balance_minor');
            $table->bigInteger('required_top_up_minor')->default(0);
            $table->string('currency_code', 3)->default('BDT');

            $table->bigInteger('low_balance_threshold_minor')->nullable();
            $table->bigInteger('critical_balance_threshold_minor')->nullable();
            $table->unsignedSmallInteger('grace_period_days')->nullable();
            $table->boolean('deposit_usable_for_charges')->default(true);

            // Why it was captured — activation, a package change, a website
            // setup, an administrator reapplying the rules.
            $table->string('source', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('deposit_due_at')->nullable();
            $table->timestamp('captured_at');

            $table->index(['wallet_id', 'id']);
            $table->index(['business_account_id', 'captured_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER wallet_deposit_obligations_no_update
                BEFORE UPDATE ON wallet_deposit_obligations
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER wallet_deposit_obligations_no_delete
                BEFORE DELETE ON wallet_deposit_obligations
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS wallet_deposit_obligations_no_update ON wallet_deposit_obligations;
            DROP TRIGGER IF EXISTS wallet_deposit_obligations_no_delete ON wallet_deposit_obligations;
        SQL);

        Schema::dropIfExists('wallet_deposit_obligations');

        Schema::table('wallets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposit_rule_id');
            $table->dropColumn([
                'minimum_balance_minor',
                'deposit_usable_for_charges',
                'obligation_captured_at',
                'deposit_due_at',
            ]);
        });
    }
};
