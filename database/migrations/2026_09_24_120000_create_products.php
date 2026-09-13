<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The central product catalogue (§11.1).
 *
 * One row per product master record, owned by Feriwala (§12). Business accounts
 * never write here — they select from it for dropshipping and buy from it for
 * wholesale, and both of those are separate tables pointing at this one.
 *
 * The §11.1 field set is spread across the P3.A tasks that own each part of it:
 * variations, media, price tiers, order-quantity and selling-price bounds,
 * status, eligibility, channel flags, related and featured, SEO and schema each
 * arrive with their own migration. What lands here is the identity, the
 * description, the placement and the two figures every product has.
 *
 * **Available stock is deliberately not a column.** Stock belongs to the
 * inventory tables (P3.C), where it is held per warehouse with reservations
 * against it. A copy on the product would be a second answer to "how many are
 * there", and the two would disagree the first time an order reserved a unit.
 *
 * Money is BIGINT minor units beside a currency code (D4). The two CHECK
 * constraints are the database's half of "a price is never negative": the form
 * refuses one, but a seeder, a console command or a future import path does not
 * go through the form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // Public URLs carry this, never the id (§34.3).
            $table->string('slug')->unique();

            /*
             * The central SKU (§12). Unique, and stored upper-case with a CHECK
             * holding it there, so the unique index is case-insensitive in
             * effect: "fw-1043" and "FW-1043" are one product to a warehouse
             * picker, and must be one row here.
             */
            $table->string('sku', 64)->unique();

            // "Where applicable" (§11.1). Unique when present; Postgres treats
            // nulls as distinct, so any number of products may have none.
            $table->string('barcode', 64)->nullable()->unique();

            $table->string('name', 160);
            $table->string('short_description', 500)->nullable();
            $table->text('description')->nullable();

            /*
             * One category — the most specific — with the parent read from the
             * tree (§11.1's "Category" and "Subcategory"). Restricted on delete:
             * a category holding products is switched off, not removed, and the
             * database refuses the removal even if an application check is
             * bypassed.
             */
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();

            $table->char('currency_code', 3)->default('BDT');

            // What Feriwala pays. Internal: never sent to a partner, a
            // storefront, or a business account (storefront contract §5.1).
            $table->bigInteger('base_cost_minor')->default(0);

            // What a business account pays per unit when buying wholesale (§10.2).
            $table->bigInteger('wholesale_price_minor')->default(0);

            /*
             * The §11.2 lifecycle. A plain string until P3-8 gives it the enum
             * and its transitions; every product starts as a draft.
             */
            $table->string('status', 30)->default('draft');

            $table->timestamps();

            $table->index(['status', 'category_id']);
            $table->index('brand_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                ADD CONSTRAINT products_sku_upper CHECK (sku = UPPER(sku)),
                ADD CONSTRAINT products_base_cost_not_negative CHECK (base_cost_minor >= 0),
                ADD CONSTRAINT products_wholesale_price_not_negative CHECK (wholesale_price_minor >= 0);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
