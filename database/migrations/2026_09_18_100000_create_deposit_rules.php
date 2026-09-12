<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an account is required to deposit and to keep (§24.1).
 *
 * One row is one dated decision, in one scope. A rule is never edited into a new
 * figure: raising a minimum balance is a new row with its own window and the old
 * one closed, so "what was this account required to hold in March" has an answer
 * and an obligation captured in March reproduces itself.
 *
 * Scope and precedence are not invented here — they are {@see RuleScope} and
 * {@see RuleResolver}, shared with commission, withdrawal and referral rules so
 * that "most specific wins" behaves identically everywhere rather than growing
 * four subtly different precedence bugs. §24.1's six subjects map onto it
 * directly: global, package, user, website, domain, hosting.
 *
 * `scope_id` carries no foreign key on purpose. It points at a different table
 * per scope, and three of the six — website, domain, hosting — are modules that
 * do not exist yet. A constraint that could only be written for half the scopes
 * would be a rule enforced for some accounts and not others; the resolver
 * matches on scope **and** id together, so a stale id resolves to nothing rather
 * than to somebody else's deposit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_rules', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->string('scope', 20);

            // Null for a global rule: there is no record for it to point at.
            $table->unsignedBigInteger('scope_id')->nullable();

            /*
             * §24.1's configurable figures. All money is minor units (D4), and
             * zero is a real answer — "no deposit required" — distinct from a
             * rule that does not exist.
             */
            $table->bigInteger('required_initial_deposit_minor')->default(0);
            $table->bigInteger('minimum_balance_minor')->default(0);
            $table->bigInteger('required_top_up_minor')->default(0);
            $table->string('currency_code', 3)->default('BDT');

            // How long after the obligation is captured the deposit is due, and
            // how long after falling short the account keeps trading.
            $table->unsignedSmallInteger('deposit_deadline_days')->nullable();
            $table->unsignedSmallInteger('grace_period_days')->nullable();

            // One-time or recurring (§24.1), with the interval where it repeats.
            $table->string('frequency', 20)->default('one_time');
            $table->unsignedSmallInteger('frequency_days')->nullable();

            /*
             * The two thresholds §24.1 names. Low is a warning; critical is the
             * point the graded actions of §24.3 begin. Null means "not
             * configured", which is not the same as zero.
             */
            $table->bigInteger('low_balance_threshold_minor')->nullable();
            $table->bigInteger('critical_balance_threshold_minor')->nullable();

            /*
             * §24.1's restriction and restoration rules, as the individual
             * decisions §24.3 grades them into. Every one defaults to off: a
             * deposit requirement that disabled accounts the moment it was
             * created would be a policy nobody chose.
             */
            $table->boolean('restricts_chargeable_services')->default(false);
            $table->boolean('pauses_website_setup')->default(false);
            $table->boolean('disables_website')->default(false);
            $table->boolean('restricts_account')->default(false);
            $table->boolean('disables_account')->default(false);
            $table->boolean('restores_automatically')->default(true);

            // Manual tie-break between rules of the same scope (ScopedRule).
            $table->integer('priority')->default(0);

            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->boolean('is_active')->default(true);

            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['scope', 'scope_id', 'is_active']);
            $table->index(['is_active', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_rules');
    }
};
