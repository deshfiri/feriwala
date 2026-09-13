<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which partners may see a product: by package, and optionally by account
 * (§11.1, §12).
 *
 * Two scopes on the product say what the two lists mean, so an empty list never
 * has to be interpreted. **A new product is offered to no package** until one is
 * chosen — the reading that assumes the least — and to any account that the
 * package rules let through. §12 requires every dropshipping product to be
 * "eligible for the selected Package"; a default of every package would make
 * that true by accident rather than by decision.
 *
 * The allow-lists restrict on delete. Packages are archived rather than removed,
 * and a business account is never hard-deleted while it has records; a row here
 * naming one is a reason to keep it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('package_scope', 20)->default('selected')->after('status');
            $table->string('account_scope', 20)->default('any')->after('package_scope');
        });

        Schema::create('product_package_eligibility', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['product_id', 'package_id']);
            $table->index('package_id');
        });

        Schema::create('product_user_eligibility', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['product_id', 'business_account_id']);
            $table->index('business_account_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                ADD CONSTRAINT products_package_scope_known CHECK (package_scope IN ('all', 'selected')),
                ADD CONSTRAINT products_account_scope_known CHECK (account_scope IN ('any', 'selected'));
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_user_eligibility');
        Schema::dropIfExists('product_package_eligibility');

        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                DROP CONSTRAINT IF EXISTS products_package_scope_known,
                DROP CONSTRAINT IF EXISTS products_account_scope_known;
        SQL);

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['package_scope', 'account_scope']);
        });
    }
};
