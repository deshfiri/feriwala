<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores a partner runs on another platform, and the orders they take (D29).
 *
 * **A read-only mirror, not a second order system.** An external store is the
 * partner's own shop on its own platform, with its own checkout, products and
 * fulfilment. Feriwala is not the merchant of record for what it sells (D29
 * beside D12), so none of this touches `orders`, `order_items`, payments, the
 * wallet, the ledger, stock or earnings: an external order is written down as
 * the store reported it, and the store stays the source of truth.
 *
 *   - `external_stores` — one connection, owned by one business account. The
 *     credentials are encrypted at rest and never leave the server. A store's
 *     address may be connected to only one live account at a time.
 *   - `external_store_status_history` — every move of a connection, in the
 *     shared shape, append-only.
 *   - `external_customers` — the people who bought, **per store**. Never merged
 *     across stores on a matching name or number.
 *   - `external_orders` / `external_order_items` — the order as reported, keyed
 *     by the store's own identifiers so a sync repeated any number of times
 *     cannot write it twice. Lines are snapshots with no link to Feriwala's
 *     catalogue; amounts are flat Taka (D26) and add up by constraint.
 *   - `external_order_events` — what the store told us happened to an order,
 *     and when: the state it arrived in, every change after. Append-only, and
 *     nothing in it is invented.
 *   - `external_store_webhook_events` — every signed notification received,
 *     unique per store and event id, so a replay is recognised, not re-applied.
 */
return new class extends Migration
{
    private const STORE_STATUSES = ['pending', 'connected', 'failing', 'disconnected'];

    private const STORE_SOURCES = ['account', 'system', 'scheduler'];

    private const PLATFORMS = ['dfcommerce'];

    private const EVENT_TYPES = ['imported', 'status_changed', 'payment_status_changed', 'payment_received'];

    private const EVENT_SOURCES = ['external_store', 'system'];

    private const WEBHOOK_STATES = ['received', 'processed', 'ignored', 'failed'];

    public function up(): void
    {
        Schema::create('external_stores', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();

            $table->string('platform', 24);
            $table->string('name', 120);
            $table->string('base_url', 255);
            // Lowercased host (and port, when not the default) — what "the same
            // store" means for the one-live-owner rule below.
            $table->string('normalized_host', 255);

            // Encrypted at rest. Nullable because disconnecting forgets them:
            // a connection nobody uses should not keep a key to somebody's shop.
            $table->text('api_key')->nullable();
            $table->text('api_secret')->nullable();
            $table->string('api_key_hint', 8)->nullable();
            $table->text('webhook_secret')->nullable();

            $table->string('status', 16);

            // Two cursors, kept apart: one walks new orders by id, the other
            // re-reads orders the store changed. A shared watermark lets one
            // pass's success hide the other's failure.
            $table->unsignedBigInteger('order_cursor')->default(0);
            $table->timestamp('orders_updated_through')->nullable();

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_account_id', 'status']);
        });

        Schema::create('external_store_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_store_id')->constrained('external_stores')->restrictOnDelete();

            $table->string('previous_status', 16)->nullable();
            $table->string('new_status', 16);
            $table->string('source', 16);

            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();

            $table->index(['external_store_id', 'id']);
        });

        Schema::create('external_customers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();
            $table->foreignId('external_store_id')->constrained('external_stores')->restrictOnDelete();

            // The store's own customer id when it has one; otherwise the
            // customer is known by phone, then email, inside this store only.
            $table->string('identity_key', 191);
            $table->string('external_id', 64)->nullable();
            $table->string('name', 160)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->timestamps();

            $table->unique(['external_store_id', 'identity_key']);
            $table->index(['business_account_id', 'last_order_at']);
        });

        Schema::create('external_orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();
            $table->foreignId('external_store_id')->constrained('external_stores')->restrictOnDelete();
            $table->foreignId('external_customer_id')->nullable()->constrained('external_customers')->restrictOnDelete();

            $table->string('platform', 24);
            $table->string('external_id', 64);
            $table->string('order_number', 64);

            // As the store reported them. Not Feriwala's order statuses, and
            // never moved by anything here but the store itself.
            $table->string('source_status', 40);
            $table->string('payment_status', 40)->nullable();
            $table->string('payment_method', 40)->nullable();

            $table->jsonb('customer');
            $table->jsonb('shipping_address')->nullable();
            $table->jsonb('billing_address')->nullable();

            $table->char('currency_code', 3)->default('BDT');
            $table->decimal('subtotal', 19, 2);
            $table->decimal('discount', 19, 2);
            $table->string('coupon_code', 64)->nullable();
            $table->decimal('shipping_total', 19, 2);
            $table->decimal('tax', 19, 2);
            $table->decimal('total', 19, 2);
            $table->decimal('paid_total', 19, 2)->nullable();

            $table->text('note')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            $table->timestamp('placed_at');
            // The store's own "last changed" time: an older copy of the order
            // never overwrites a newer one.
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('last_synced_at');
            $table->timestamps();

            $table->unique(['external_store_id', 'external_id']);
            $table->unique(['external_store_id', 'order_number']);
            $table->index(['business_account_id', 'placed_at']);
            $table->index(['external_customer_id', 'placed_at']);
        });

        Schema::create('external_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_order_id')->constrained('external_orders')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');

            $table->string('external_product_id', 64)->nullable();
            $table->string('sku', 120)->nullable();
            $table->string('name', 255);
            $table->string('variant_label', 255)->nullable();
            $table->unsignedInteger('quantity');

            $table->char('currency_code', 3)->default('BDT');
            $table->decimal('unit_price', 19, 2);
            $table->decimal('line_total', 19, 2);

            $table->timestamp('created_at')->nullable();

            $table->unique(['external_order_id', 'line_number']);
        });

        Schema::create('external_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_order_id')->constrained('external_orders')->restrictOnDelete();

            $table->string('type', 32);
            $table->string('source', 16);
            $table->string('previous_value', 40)->nullable();
            $table->string('new_value', 40)->nullable();
            $table->string('description', 255)->nullable();
            // The webhook event this came from, when it came from one.
            $table->string('external_event_id', 100)->nullable();

            // When it happened at the store, as far as the store said; and when
            // Feriwala learned of it. Never the same thing assumed.
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('recorded_at');

            $table->index(['external_order_id', 'id']);
        });

        Schema::create('external_store_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_store_id')->constrained('external_stores')->restrictOnDelete();

            $table->string('event_id', 100);
            $table->string('event', 64);
            $table->string('state', 16);
            $table->jsonb('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->string('error', 255)->nullable();

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('last_duplicate_at')->nullable();
            $table->timestamps();

            $table->unique(['external_store_id', 'event_id']);
            $table->index(['external_store_id', 'state']);
        });

        $statuses = self::quoted(self::STORE_STATUSES);
        $sources = self::quoted(self::STORE_SOURCES);
        $platforms = self::quoted(self::PLATFORMS);
        $eventTypes = self::quoted(self::EVENT_TYPES);
        $eventSources = self::quoted(self::EVENT_SOURCES);
        $webhookStates = self::quoted(self::WEBHOOK_STATES);

        DB::unprepared(<<<SQL
            ALTER TABLE external_stores
                ADD CONSTRAINT external_stores_platform_known CHECK (platform IN ({$platforms})),
                ADD CONSTRAINT external_stores_status_known CHECK (status IN ({$statuses})),
                ADD CONSTRAINT external_stores_host_lowercase CHECK (normalized_host = lower(normalized_host)),
                ADD CONSTRAINT external_stores_https CHECK (base_url LIKE 'https://%'),
                ADD CONSTRAINT external_stores_live_has_credentials CHECK (
                    status = 'disconnected' OR (api_key IS NOT NULL AND api_secret IS NOT NULL AND webhook_secret IS NOT NULL)
                ),
                ADD CONSTRAINT external_stores_disconnection_is_recorded CHECK (
                    status <> 'disconnected' OR disconnected_at IS NOT NULL
                );

            -- One live owner per store address. A disconnected row keeps its
            -- history and its orders, and no longer holds the address.
            CREATE UNIQUE INDEX external_stores_one_live_owner
                ON external_stores (platform, normalized_host)
                WHERE status <> 'disconnected';

            CREATE TRIGGER external_stores_locked_columns
                BEFORE UPDATE OF public_id, business_account_id, platform ON external_stores
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'business_account_id', 'platform');

            ALTER TABLE external_store_status_history
                ADD CONSTRAINT external_store_status_history_previous_known CHECK (
                    previous_status IS NULL OR previous_status IN ({$statuses})
                ),
                ADD CONSTRAINT external_store_status_history_new_known CHECK (new_status IN ({$statuses})),
                ADD CONSTRAINT external_store_status_history_source_known CHECK (source IN ({$sources})),
                ADD CONSTRAINT external_store_status_history_is_a_change CHECK (previous_status IS DISTINCT FROM new_status);

            CREATE TRIGGER external_store_status_history_no_update
                BEFORE UPDATE ON external_store_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER external_store_status_history_no_delete
                BEFORE DELETE ON external_store_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER external_customers_locked_columns
                BEFORE UPDATE OF public_id, business_account_id, external_store_id, identity_key ON external_customers
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'business_account_id', 'external_store_id', 'identity_key');

            ALTER TABLE external_orders
                ADD CONSTRAINT external_orders_platform_known CHECK (platform IN ({$platforms})),
                ADD CONSTRAINT external_orders_amounts_not_negative CHECK (
                    subtotal >= 0 AND discount >= 0 AND shipping_total >= 0 AND tax >= 0 AND total >= 0
                    AND (paid_total IS NULL OR paid_total >= 0)
                );

            -- An order belongs to the store it came from, and the store to one
            -- account. Neither moves, and the account always agrees.
            CREATE TRIGGER external_orders_locked_columns
                BEFORE UPDATE OF public_id, business_account_id, external_store_id, platform, external_id ON external_orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'business_account_id', 'external_store_id', 'platform', 'external_id');

            CREATE OR REPLACE FUNCTION feriwala_external_order_matches_store() RETURNS trigger AS \$\$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM external_stores
                     WHERE id = NEW.external_store_id
                       AND business_account_id = NEW.business_account_id
                       AND platform = NEW.platform
                ) THEN
                    RAISE EXCEPTION 'An external order must belong to its store''s account and platform.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.external_customer_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM external_customers
                     WHERE id = NEW.external_customer_id AND external_store_id = NEW.external_store_id
                ) THEN
                    RAISE EXCEPTION 'An external order''s customer must belong to the same store.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER external_orders_match_store
                BEFORE INSERT OR UPDATE ON external_orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_external_order_matches_store();

            CREATE OR REPLACE FUNCTION feriwala_external_order_is_never_deleted() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'External orders are kept: % is not permitted.', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER external_orders_never_deleted
                BEFORE DELETE ON external_orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_external_order_is_never_deleted();

            ALTER TABLE external_order_items
                ADD CONSTRAINT external_order_items_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT external_order_items_line_number_positive CHECK (line_number >= 1),
                ADD CONSTRAINT external_order_items_amounts_not_negative CHECK (unit_price >= 0 AND line_total >= 0);

            ALTER TABLE external_order_events
                ADD CONSTRAINT external_order_events_type_known CHECK (type IN ({$eventTypes})),
                ADD CONSTRAINT external_order_events_source_known CHECK (source IN ({$eventSources}));

            CREATE TRIGGER external_order_events_no_update
                BEFORE UPDATE ON external_order_events
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER external_order_events_no_delete
                BEFORE DELETE ON external_order_events
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            ALTER TABLE external_store_webhook_events
                ADD CONSTRAINT external_store_webhook_events_state_known CHECK (state IN ({$webhookStates}));
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('external_store_webhook_events');
        Schema::dropIfExists('external_order_events');
        Schema::dropIfExists('external_order_items');
        Schema::dropIfExists('external_orders');
        Schema::dropIfExists('external_customers');
        Schema::dropIfExists('external_store_status_history');
        Schema::dropIfExists('external_stores');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS feriwala_external_order_matches_store();
            DROP FUNCTION IF EXISTS feriwala_external_order_is_never_deleted();
        SQL);
    }

    /**
     * @param  list<literal-string>  $values
     * @return literal-string
     */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $value) => "'{$value}'", $values));
    }
};
