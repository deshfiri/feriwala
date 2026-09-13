<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Related products and featured status (§11.1, §13).
 *
 * A relation is one-directional and ordered: "customers who look at this rice
 * cooker should see this steamer" says nothing about what the steamer's page
 * should show, and the order is the order a partner's page presents them in.
 * A product is never related to itself — a CHECK, because a page recommending
 * the page it is on is a mistake no form should be able to save.
 *
 * `featured_at` records when a product was last featured, so "featured" has a
 * date an administrator can be asked about, and featured products can be shown
 * newest first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('wholesale_status');
            $table->timestamp('featured_at')->nullable()->after('is_featured');

            $table->index(['is_featured', 'status']);
        });

        Schema::create('product_related', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('related_product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['product_id', 'related_product_id']);
            $table->index(['product_id', 'position']);
            $table->index('related_product_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE product_related
                ADD CONSTRAINT product_related_not_self CHECK (product_id <> related_product_id),
                ADD CONSTRAINT product_related_position_positive CHECK (position >= 1);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_related');

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_featured', 'status']);
            $table->dropColumn(['is_featured', 'featured_at']);
        });
    }
};
