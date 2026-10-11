<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The website a confirmed package purchase entitles an account to (§16.1, §16.2).
 *
 * One row per subscription that includes a dedicated website, written when the
 * subscription becomes active — never when a website is requested, configured
 * or launched. `starts_at` is the authoritative start of the package-based
 * domain and hosting period: the subscription's own `started_at`, which is the
 * moment the purchase was confirmed (after any admin approval), not when the
 * payment was submitted.
 *
 * `user_package_id` is unique, so a payment callback that arrives twice or an
 * approval that is clicked twice cannot allocate a second website for the same
 * purchase. `website_id` fills in once the partner sets the website up, and
 * carries over to the allocation a renewal or package change writes, so one
 * website keeps one line of terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_allocations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();

            // One allocation per confirmed purchase. The index, not a lookup,
            // is what refuses a duplicate from a repeated callback.
            $table->foreignId('user_package_id')->unique()->constrained('user_packages')->restrictOnDelete();

            // The website set up against it. Empty until the partner sets it
            // up; never cleared by editing or activating that website.
            $table->foreignId('website_id')->nullable()->constrained('websites')->nullOnDelete();

            // The allocation this one continues, for a renewal or a package change.
            $table->foreignId('previous_allocation_id')->nullable()->constrained('website_allocations')->nullOnDelete();

            // When the purchase was confirmed: the subscription's own start.
            $table->timestamp('starts_at');

            // When each package-based period starts. The same as `starts_at`
            // for a first purchase; for a renewal, the later of the renewal
            // and the end of the period it continues, so renewing early never
            // shortens or restarts a domain or hosting term already paid for.
            $table->timestamp('domain_starts_at');
            $table->timestamp('hosting_starts_at');

            // What the purchase included, as it was bought (§8.3).
            $table->boolean('domain_included')->default(false);
            $table->boolean('hosting_included')->default(false);
            $table->unsignedSmallInteger('domain_validity_months')->nullable();
            $table->unsignedSmallInteger('hosting_validity_months')->nullable();

            // Null when the term does not end.
            $table->timestamp('domain_expires_at')->nullable();
            $table->timestamp('hosting_expires_at')->nullable();

            $table->timestamps();

            $table->index(['business_account_id', 'website_id']);
        });

        // An expiry can never fall before the period it ends started.
        DB::statement(<<<'SQL'
            ALTER TABLE website_allocations
            ADD CONSTRAINT website_allocations_domain_expiry_after_start
            CHECK (domain_expires_at IS NULL OR domain_expires_at >= domain_starts_at)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE website_allocations
            ADD CONSTRAINT website_allocations_hosting_expiry_after_start
            CHECK (hosting_expires_at IS NULL OR hosting_expires_at >= hosting_starts_at)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('website_allocations');
    }
};
