<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders from partner websites, and the customers who place them
 * (§16.3, §17.1, §17.3, contract §4.7, §6.1, §6.2, P5-21, P5-23).
 *
 * **Customers.** A storefront customer belongs to one website and is keyed on
 * its normalised mobile number there: the same number on two partners' shops is
 * two customers, and neither shop can learn of the other (contract §6.2). A
 * guest is never an ERP user.
 *
 * **Orders.** A website order is an order like any other — the same table, the
 * same status map, the same snapshots — with what only a website order has:
 * the customer it was placed by, the storefront's own reference, and where to
 * send the customer back after paying. Each is fixed once written. The database
 * holds the guarantees the intake relies on rather than a check in code:
 *
 *   - a website order is complete: its website, customer, reference, payment,
 *     idempotency key and both addresses, and no cart or placing user;
 *   - its website belongs to its business account, and its customer to its
 *     website;
 *   - one order per storefront reference **per website** — the same reference
 *     on two websites is two orders;
 *   - one payment per website order, and the other way round.
 *
 * **Requests.** A storefront write is answered once per idempotency key per
 * website, and the answer is kept here — durably, so a replay after a cache
 * flush still gets the stored response and never a second order (contract §4.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_customers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('website_id')->constrained('websites')->restrictOnDelete();

            // E.164, as normalised on receipt (contract §4.3.1).
            $table->string('mobile', 16);
            $table->string('name', 160);
            $table->string('email', 254)->nullable();
            $table->string('storefront_customer_reference', 64)->nullable();
            $table->boolean('is_guest')->default(true);

            $table->timestamp('last_order_at')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'mobile']);
        });

        Schema::create('storefront_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->restrictOnDelete();
            $table->string('idempotency_key', 64);
            $table->string('method', 8);
            $table->string('path', 255);
            $table->char('fingerprint', 64);

            // The answer, kept as it was sent: a replay is the same bytes.
            $table->unsignedSmallInteger('response_status');
            $table->text('response_body');
            $table->timestamp('created_at');

            $table->unique(['website_id', 'idempotency_key']);
            $table->index('created_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            // `orders.website_id` already names websites, with its index, from
            // when websites were created.
            $table->foreignId('website_customer_id')->nullable()->after('website_id')
                ->constrained('website_customers')->restrictOnDelete();
            $table->string('storefront_order_reference', 64)->nullable()->after('website_customer_id');
            $table->string('storefront_return_url', 2048)->nullable()->after('storefront_order_reference');

            $table->index(['website_id', 'placed_at']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            // The website's selection this line was sold from, and at whose price.
            $table->foreignId('website_product_id')->nullable()->after('product_variant_id')
                ->constrained('website_products')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE website_customers
                ADD CONSTRAINT website_customers_mobile_is_e164 CHECK (mobile ~ '^\+[1-9][0-9]{7,14}$'),
                ADD CONSTRAINT website_customers_name_present CHECK (length(btrim(name)) > 0);

            CREATE UNIQUE INDEX website_customers_one_per_storefront_reference
                ON website_customers (website_id, storefront_customer_reference)
                WHERE storefront_customer_reference IS NOT NULL;

            CREATE TRIGGER website_customers_locked_columns
                BEFORE UPDATE OF public_id, website_id ON website_customers
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'website_id');

            ALTER TABLE storefront_requests
                ADD CONSTRAINT storefront_requests_status_is_an_answer CHECK (response_status BETWEEN 200 AND 499);

            ALTER TABLE orders
                ADD CONSTRAINT orders_website_is_complete CHECK (
                    source <> 'website' OR (
                        website_id IS NOT NULL AND website_customer_id IS NOT NULL
                        AND storefront_order_reference IS NOT NULL AND payment_id IS NOT NULL
                        AND idempotency_key IS NOT NULL AND billing_address IS NOT NULL
                        AND shipping_address IS NOT NULL AND cart_id IS NULL AND placed_by IS NULL
                    )
                ),
                ADD CONSTRAINT orders_storefront_fields_only_on_website_orders CHECK (
                    source = 'website' OR (
                        website_customer_id IS NULL AND storefront_order_reference IS NULL
                        AND storefront_return_url IS NULL
                    )
                );

            -- The same storefront reference on two websites is two orders; on one, it is one.
            CREATE UNIQUE INDEX orders_one_per_storefront_reference
                ON orders (website_id, storefront_order_reference)
                WHERE storefront_order_reference IS NOT NULL;

            -- One payment, one website order, both ways round.
            CREATE UNIQUE INDEX payments_one_website_payment_per_order ON payments (payable_type, payable_id)
                WHERE purpose = 'website_order' AND payable_id IS NOT NULL;

            CREATE TRIGGER orders_website_columns_locked
                BEFORE UPDATE OF website_customer_id, storefront_order_reference, storefront_return_url ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'website_customer_id', 'storefront_order_reference', 'storefront_return_url'
                );

            CREATE OR REPLACE FUNCTION feriwala_website_order_belongs_to_its_shop() RETURNS trigger AS $$
            BEGIN
                IF NEW.website_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM websites WHERE id = NEW.website_id AND business_account_id = NEW.business_account_id
                ) THEN
                    RAISE EXCEPTION 'an order''s website must belong to the order''s business account'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF NEW.website_customer_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM website_customers WHERE id = NEW.website_customer_id AND website_id = NEW.website_id
                ) THEN
                    RAISE EXCEPTION 'an order''s customer must be a customer of the order''s website'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER orders_website_belongs_to_its_shop
                BEFORE INSERT ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_website_order_belongs_to_its_shop();

            CREATE OR REPLACE FUNCTION feriwala_order_item_selection_matches() RETURNS trigger AS $$
            BEGIN
                IF NEW.website_product_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1
                    FROM website_products
                    JOIN orders ON orders.id = NEW.order_id
                    WHERE website_products.id = NEW.website_product_id
                      AND website_products.product_id = NEW.product_id
                      AND website_products.website_id = orders.website_id
                ) THEN
                    RAISE EXCEPTION 'an order line''s website selection must be of its product, on its order''s website'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_items_selection_matches
                BEFORE INSERT ON order_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_item_selection_matches();

            -- A storefront moving an order — the customer cancelling before paying.
            ALTER TABLE order_status_history
                DROP CONSTRAINT order_status_history_source_known,
                ADD CONSTRAINT order_status_history_source_known CHECK (
                    source IN ('checkout', 'payment_gateway', 'scheduler', 'account', 'staff', 'system', 'storefront')
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE order_status_history
                DROP CONSTRAINT order_status_history_source_known,
                ADD CONSTRAINT order_status_history_source_known CHECK (
                    source IN ('checkout', 'payment_gateway', 'scheduler', 'account', 'staff', 'system')
                );

            DROP TRIGGER IF EXISTS order_items_selection_matches ON order_items;
            DROP FUNCTION IF EXISTS feriwala_order_item_selection_matches();
            DROP TRIGGER IF EXISTS orders_website_belongs_to_its_shop ON orders;
            DROP FUNCTION IF EXISTS feriwala_website_order_belongs_to_its_shop();
            DROP TRIGGER IF EXISTS orders_website_columns_locked ON orders;
            DROP INDEX IF EXISTS payments_one_website_payment_per_order;
            DROP INDEX IF EXISTS orders_one_per_storefront_reference;

            ALTER TABLE orders
                DROP CONSTRAINT IF EXISTS orders_website_is_complete,
                DROP CONSTRAINT IF EXISTS orders_storefront_fields_only_on_website_orders;
        SQL);

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('website_product_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['website_id', 'placed_at']);
            $table->dropColumn(['storefront_order_reference', 'storefront_return_url']);
            $table->dropConstrainedForeignId('website_customer_id');
        });

        Schema::dropIfExists('storefront_requests');
        Schema::dropIfExists('website_customers');
    }
};
