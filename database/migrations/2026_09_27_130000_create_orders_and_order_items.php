<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders and their lines (§18, P6-1, P6-2).
 *
 * **Every field §18 makes mandatory**: a unique reference, the source, the
 * account and the person who placed it, the website where one applies, a
 * customer snapshot, the products (as lines), the payment, fulfillment, courier
 * and delivery status, the financial breakdown, and a note. Status history is
 * its own table (P6-6).
 *
 * **What was bought, and for how much, is fixed once written.** An order and its
 * lines are snapshots — product, SKU, quantity, unit price, discount, tax, total,
 * addresses — so a price change, a renamed product or an edited address never
 * rewrites an order somebody paid for. The financial columns must add up, and
 * the database refuses a change to any of them. An order is never deleted.
 *
 * **One payment, one order.** `payment_id` is unique, and so is the payment's
 * link back to its order for a wholesale payment; an idempotency key is unique,
 * and a cart has at most one wholesale order waiting for payment. These are the
 * guarantees a retried request or a repeated gateway callback relies on, so the
 * database holds them rather than a check in code.
 *
 * **A status moves only along the transition map** (P6-5), by trigger.
 */
return new class extends Migration
{
    /** Columns an order may never change after it is placed. */
    private const LOCKED_ORDER_COLUMNS = [
        'public_id', 'reference', 'source', 'business_account_id', 'website_id', 'payment_id',
        'idempotency_key', 'checkout_fingerprint', 'customer', 'billing_address', 'shipping_address',
        'currency_code', 'subtotal_minor', 'discount_minor', 'delivery_minor', 'tax_minor',
        'tax_included_minor', 'cod_fee_minor', 'total_minor', 'coupon_code', 'placed_at',
    ];

    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();

            $table->string('source', 24);
            $table->string('status', 40);
            $table->foreign('status')->references('code')->on('order_statuses')->restrictOnDelete();

            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();
            $table->foreignId('placed_by')->nullable()->constrained('users')->nullOnDelete();

            // The dedicated website an order came through (§18). Websites arrive
            // with Phase 5, and the foreign key with them.
            $table->unsignedBigInteger('website_id')->nullable();

            // The cart a wholesale order was checked out from, while it exists.
            $table->foreignId('cart_id')->nullable()->constrained('carts')->nullOnDelete();

            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->char('checkout_fingerprint', 64)->nullable();

            // Who the order is for, as it was when it was placed.
            $table->jsonb('customer');
            $table->jsonb('billing_address')->nullable();
            $table->jsonb('shipping_address')->nullable();

            $table->char('currency_code', 3)->default('BDT');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('delivery_minor')->default(0);
            // Tax added to the total, and tax already inside the prices (shown, never added).
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('tax_included_minor')->default(0);
            $table->bigInteger('cod_fee_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->string('coupon_code', 64)->nullable();

            $table->string('fulfillment_status', 32)->default('unfulfilled');
            $table->string('courier_status', 32)->default('unassigned');
            $table->string('delivery_status', 32)->default('not_shipped');

            $table->text('customer_note')->nullable();

            $table->timestamp('placed_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->text('hold_reason')->nullable();
            $table->timestamps();

            $table->index(['business_account_id', 'placed_at']);
            $table->index(['status', 'placed_at']);
            $table->index('source');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');

            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();

            // What was bought, as it was named and priced when it was bought.
            $table->string('sku', 64);
            $table->string('product_name', 160);
            $table->string('variant_label')->nullable();
            $table->integer('quantity');

            $table->char('currency_code', 3)->default('BDT');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('line_subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('tax_included_minor')->default(0);
            $table->bigInteger('line_total_minor');

            // The rate this line was taxed at, copied in so next year's rate cannot rewrite it.
            $table->string('tax_code', 64)->nullable();
            $table->unsignedInteger('tax_rate_basis_points')->nullable();
            $table->string('tax_mode', 16)->nullable();

            // The central stock held for this line (§19.1, P4-10).
            $table->foreignId('stock_reservation_id')->nullable()->unique()->constrained('stock_reservations')->restrictOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->unique(['order_id', 'line_number']);
        });

        $locked = implode(', ', self::LOCKED_ORDER_COLUMNS);
        $lockedArguments = implode(', ', array_map(fn (string $column) => "'{$column}'", self::LOCKED_ORDER_COLUMNS));

        DB::unprepared(<<<SQL
            ALTER TABLE orders
                ADD CONSTRAINT orders_source_known CHECK (source IN ('erp_wholesale', 'website', 'manual', 'api', 'admin', 'external')),
                ADD CONSTRAINT orders_fulfillment_status_known CHECK (fulfillment_status IN ('unfulfilled')),
                ADD CONSTRAINT orders_courier_status_known CHECK (courier_status IN ('unassigned')),
                ADD CONSTRAINT orders_delivery_status_known CHECK (delivery_status IN ('not_shipped')),
                ADD CONSTRAINT orders_amounts_not_negative CHECK (
                    subtotal_minor >= 0 AND discount_minor >= 0 AND delivery_minor >= 0 AND tax_minor >= 0
                    AND tax_included_minor >= 0 AND cod_fee_minor >= 0 AND total_minor >= 0
                ),
                ADD CONSTRAINT orders_discount_within_subtotal CHECK (discount_minor <= subtotal_minor),
                ADD CONSTRAINT orders_total_adds_up CHECK (
                    total_minor = subtotal_minor - discount_minor + delivery_minor + tax_minor + cod_fee_minor
                ),
                ADD CONSTRAINT orders_wholesale_is_complete CHECK (
                    source <> 'erp_wholesale' OR (
                        website_id IS NULL AND placed_by IS NOT NULL AND payment_id IS NOT NULL
                        AND idempotency_key IS NOT NULL AND billing_address IS NOT NULL AND shipping_address IS NOT NULL
                    )
                ),
                ADD CONSTRAINT orders_cancellation_is_recorded CHECK (status <> 'cancelled' OR cancelled_at IS NOT NULL),
                ADD CONSTRAINT orders_hold_is_recorded CHECK (status <> 'on_hold' OR (held_at IS NOT NULL AND hold_reason IS NOT NULL));

            -- One wholesale order waiting for payment per cart.
            CREATE UNIQUE INDEX orders_one_payment_pending_per_cart ON orders (cart_id)
                WHERE status = 'payment_pending' AND cart_id IS NOT NULL;

            -- The other half of one payment, one order: a wholesale payment names one order.
            CREATE UNIQUE INDEX payments_one_wholesale_payment_per_order ON payments (payable_type, payable_id)
                WHERE purpose = 'wholesale_order' AND payable_id IS NOT NULL;

            CREATE TRIGGER orders_status_follows_transition_map
                BEFORE UPDATE OF status ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_transition_is_allowed();

            CREATE TRIGGER orders_locked_columns
                BEFORE UPDATE OF {$locked} ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked({$lockedArguments});

            CREATE OR REPLACE FUNCTION feriwala_order_is_never_deleted() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION '% % cannot be deleted: an order is a record of a sale (18)', TG_TABLE_NAME, OLD.id
                    USING ERRCODE = 'restrict_violation';
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER orders_never_deleted
                BEFORE DELETE ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_is_never_deleted();

            ALTER TABLE order_items
                ADD CONSTRAINT order_items_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT order_items_line_number_positive CHECK (line_number >= 1),
                ADD CONSTRAINT order_items_amounts_not_negative CHECK (
                    unit_price_minor >= 0 AND line_subtotal_minor >= 0 AND discount_minor >= 0
                    AND tax_minor >= 0 AND tax_included_minor >= 0 AND line_total_minor >= 0
                ),
                ADD CONSTRAINT order_items_subtotal_adds_up CHECK (line_subtotal_minor = quantity * unit_price_minor),
                ADD CONSTRAINT order_items_discount_within_subtotal CHECK (discount_minor <= line_subtotal_minor),
                ADD CONSTRAINT order_items_total_adds_up CHECK (
                    line_total_minor = line_subtotal_minor - discount_minor + tax_minor
                );

            CREATE OR REPLACE FUNCTION feriwala_order_item_variant_matches_product() RETURNS trigger AS \$\$
            BEGIN
                IF NEW.product_variant_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM product_variants WHERE id = NEW.product_variant_id AND product_id = NEW.product_id
                ) THEN
                    RAISE EXCEPTION 'an order line''s variation must belong to its product'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER order_items_variant_matches_product
                BEFORE INSERT ON order_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_item_variant_matches_product();

            CREATE OR REPLACE FUNCTION feriwala_order_item_is_a_snapshot() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'order_items is a snapshot of what was bought: % is not permitted', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER order_items_are_snapshots
                BEFORE UPDATE OR DELETE ON order_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_item_is_a_snapshot();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS payments_one_wholesale_payment_per_order;');

        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS feriwala_order_is_never_deleted();
            DROP FUNCTION IF EXISTS feriwala_order_item_variant_matches_product();
            DROP FUNCTION IF EXISTS feriwala_order_item_is_a_snapshot();
        SQL);
    }
};
