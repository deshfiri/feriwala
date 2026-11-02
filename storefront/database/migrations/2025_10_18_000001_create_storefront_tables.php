<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The storefront's own data. Nothing here is the source of truth for a price,
 * stock or an order's state: those live in the ERP and are re-read from it.
 *
 * Every table is created only when it is missing, so an installation that was
 * migrated by the plain-PHP storefront (same tables, same columns) upgrades with
 * a plain `php artisan migrate` and keeps its baskets and orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The old session table held (id, data); Laravel's holds (id, payload, …).
        // Sessions are disposable, so the old shape is replaced rather than converted.
        if (Schema::hasTable('sessions') && ! Schema::hasColumn('sessions', 'payload')) {
            Schema::drop('sessions');
        }

        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        // A visitor's basket. The token is the cookie; prices are never kept here.
        if (! Schema::hasTable('carts')) {
            Schema::create('carts', function (Blueprint $table): void {
                $table->id();
                $table->char('token', 40)->unique();
                $table->timestamps();
                $table->index('updated_at');
            });
        }

        if (! Schema::hasTable('cart_items')) {
            Schema::create('cart_items', function (Blueprint $table): void {
                $table->unsignedBigInteger('cart_id');
                $table->string('sku', 64);
                $table->string('product_id', 40);
                $table->string('product_slug', 190);
                $table->string('product_name');
                $table->string('variant_label')->nullable();
                $table->string('image_url', 1024)->nullable();
                $table->unsignedInteger('quantity');
                $table->timestamps();

                $table->primary(['cart_id', 'sku']);
                $table->foreign('cart_id')->references('id')->on('carts')->cascadeOnDelete();
            });
        }

        // A checkout from details to placed order. `reference` is the unique
        // storefront_order_reference; `idempotency_key` changes whenever the
        // customer has to reconfirm after a price change (contract §6.1.1).
        if (! Schema::hasTable('checkouts')) {
            Schema::create('checkouts', function (Blueprint $table): void {
                $table->id();
                $table->char('token', 40)->unique();
                $table->unsignedBigInteger('cart_id')->nullable();
                $table->string('reference', 64)->nullable()->unique();
                $table->char('idempotency_key', 36);
                $table->string('status', 16)->default('draft');
                $table->json('customer_json');
                $table->json('shipping_json');
                $table->json('billing_json')->nullable();
                $table->json('items_json');
                $table->string('payment_method', 20);
                // The gateway a customer chose for an online payment (one the ERP offered).
                $table->string('gateway', 40)->nullable();
                $table->string('customer_note', 1000)->nullable();
                $table->json('quote_json')->nullable();
                $table->string('placed_order_id', 40)->nullable();
                $table->timestamps();

                $table->index(['status', 'updated_at'], 'checkouts_status_updated');
            });
        }

        // What this storefront knows of an order, so a customer can find it again.
        // The ERP's answer is always re-read for anything that matters.
        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 40)->unique();
                $table->string('reference', 64)->index();
                $table->string('storefront_order_reference', 64)->unique();
                $table->char('access_token', 40);
                $table->string('customer_name', 160);
                $table->string('customer_mobile', 32)->index();
                $table->string('customer_email', 254)->nullable();
                $table->string('status', 64);
                $table->string('payment_method', 20);
                $table->string('grand_total', 40);
                $table->char('currency', 3);
                $table->dateTime('placed_at');
                $table->dateTime('updated_at');
            });
        }

        // A read-through copy of catalogue and site reads, so a busy page does not
        // spend the ERP's rate limit. `tag` is what a webhook invalidates by.
        if (! Schema::hasTable('api_cache')) {
            Schema::create('api_cache', function (Blueprint $table): void {
                $table->string('cache_key', 190)->primary();
                $table->string('tag', 64)->index();
                $table->mediumText('payload');
                $table->dateTime('stored_at');
                $table->dateTime('expires_at')->index();
            });
        }

        // Webhook deliveries are at-least-once: the delivery id is the dedupe key (§7.3).
        if (! Schema::hasTable('webhook_deliveries')) {
            Schema::create('webhook_deliveries', function (Blueprint $table): void {
                $table->string('delivery_id', 64)->primary();
                $table->string('event', 64);
                $table->dateTime('occurred_at')->nullable();
                $table->dateTime('received_at')->index();
            });
        }

        // The old fixed-window counters; the rate limiter now lives in the cache.
        Schema::dropIfExists('throttle');
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('api_cache');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('checkouts');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('sessions');
    }
};
