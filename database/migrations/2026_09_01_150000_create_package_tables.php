<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Packages (§8).
 *
 * The system supports N packages — N is not fixed, and an administrator may
 * create, edit, activate, deactivate, or archive any number of them (§8).
 *
 * Money is BIGINT minor units with its own currency column throughout (D4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('slug')->unique();

            $table->string('name');
            $table->string('short_description')->nullable();
            $table->text('description')->nullable();

            // §8.1 fees. The registration fee here overrides the global one when
            // set; null means "use the global fee" (§9).
            $table->bigInteger('fee_minor')->default(0);
            $table->bigInteger('registration_fee_minor')->nullable();
            $table->bigInteger('renewal_fee_minor')->nullable();
            $table->string('currency_code', 3)->default('BDT');

            // Validity and renewal (§8.1, §8.4).
            $table->unsignedSmallInteger('validity_days')->nullable();
            $table->string('renewal_frequency', 20)->nullable();
            $table->unsignedSmallInteger('grace_period_days')->default(0);

            // Wallet requirements (§24.1). Also expressible as scoped deposit
            // rules; these are the package's own baseline.
            $table->bigInteger('required_deposit_minor')->default(0);
            $table->bigInteger('minimum_balance_minor')->default(0);

            // Availability window (§8.1).
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'is_public', 'sort_order']);
        });

        // Typed entitlements (§8.1).
        Schema::create('package_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();

            $table->string('feature', 64);

            // Null is meaningful for a limit — it means unlimited, which is why
            // this is nullable rather than defaulted.
            $table->string('value')->nullable();

            $table->timestamps();

            $table->unique(['package_id', 'feature']);
        });

        // Service charges the package carries (§8.1, §16.2).
        Schema::create('package_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();

            // website_setup, website_maintenance, domain, hosting
            $table->string('charge_type', 32);
            $table->bigInteger('amount_minor')->default(0);
            $table->string('currency_code', 3)->default('BDT');

            // How often it recurs: once, monthly, yearly.
            $table->string('frequency', 20)->default('once');

            $table->timestamps();

            $table->unique(['package_id', 'charge_type']);
        });

        // An account's subscription to a package (§8.2).
        Schema::create('user_packages', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The package is kept even if archived — an account's history must
            // still be readable after a package is withdrawn from sale.
            $table->foreignId('package_id')->constrained()->restrictOnDelete();

            $table->string('status', 32);

            // How the account came to hold it: purchase, upgrade, downgrade,
            // renewal, manual, promotional (§8.3).
            $table->string('source', 20)->default('purchase');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // What was actually charged, which may differ from the package's
            // current price — a renewal quote must not silently change because
            // an administrator edited the package afterwards.
            $table->bigInteger('paid_fee_minor')->nullable();
            $table->string('currency_code', 3)->default('BDT');

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
            $table->index('status');
        });

        // The account's current package, for the common lookup.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_user_package_id')
                ->nullable()
                ->after('activated_at')
                ->constrained('user_packages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_user_package_id']);
            $table->dropColumn('current_user_package_id');
        });

        Schema::dropIfExists('user_packages');
        Schema::dropIfExists('package_charges');
        Schema::dropIfExists('package_features');
        Schema::dropIfExists('packages');
    }
};
