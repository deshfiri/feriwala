<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The legacy reconciliation of Storefront orders (D40): courier consignments,
 * delivery, collection, account type, cost and Partner profit for orders that
 * predate the live flows.
 *
 *   - `storefront_reconciliation_runs` — one row per `--apply` run: its options,
 *     its checkpoint (the last order finished, so an interrupted run resumes
 *     rather than restarting), and its final counts.
 *   - `storefront_reconciliation_items` — one row per order per run: the final
 *     classification, its reasons and the effects it had. References and codes
 *     only; nothing about the customer.
 *   - `partner_storefront_profits.review_hold` — a reason a person must look
 *     before the profit may be credited, which the figures alone cannot clear:
 *     an earlier credit that may already represent it, or a Delivered time the
 *     records cannot prove.
 *   - `orders.account_type` stays locked, except for one correction: a
 *     Storefront order saved as `conditional` may become `non_conditional`,
 *     only inside a transaction that has said it is reconciling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('status', 16);
            $table->jsonb('options');
            $table->unsignedBigInteger('checkpoint_order_id')->nullable();
            $table->jsonb('counts')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('storefront_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_reconciliation_run_id')->constrained(indexName: 'storefront_recon_items_run_fk')->restrictOnDelete();
            $table->foreignId('order_id')->constrained(indexName: 'storefront_recon_items_order_fk')->restrictOnDelete();
            $table->string('classification', 48);
            $table->jsonb('reasons');
            $table->jsonb('effects');
            $table->text('failure_message')->nullable();
            $table->timestamp('processed_at');

            $table->unique(['storefront_reconciliation_run_id', 'order_id'], 'storefront_recon_items_run_order_unique');
            $table->index(['classification'], 'storefront_recon_items_classification');
        });

        Schema::table('partner_storefront_profits', function (Blueprint $table) {
            $table->string('review_hold', 40)->nullable();
            $table->timestamp('review_hold_at')->nullable();
            // The whole calculation as last worked out: customer total, Banij's
            // receivable (Product Base Price + delivery), tax, Partner profit.
            $table->jsonb('breakdown')->nullable();
        });

        DB::connection()->getPdo()->exec(<<<'SQL'
            ALTER TABLE storefront_reconciliation_runs
                ADD CONSTRAINT storefront_reconciliation_runs_status_known CHECK (status IN ('running', 'completed', 'failed'));

            ALTER TABLE partner_storefront_profits
                ADD CONSTRAINT partner_storefront_profits_review_hold_known CHECK (
                    review_hold IS NULL OR review_hold IN ('possible_duplicate', 'delivered_time_unproven')
                ),
                ADD CONSTRAINT partner_storefront_profits_review_hold_dated CHECK ((review_hold IS NULL) = (review_hold_at IS NULL));

            DROP TRIGGER IF EXISTS orders_account_type_locked ON orders;

            CREATE OR REPLACE FUNCTION feriwala_order_account_type_is_locked() RETURNS trigger AS $$
            BEGIN
                IF NEW.account_type IS DISTINCT FROM OLD.account_type THEN
                    IF NEW.source = 'website'
                        AND OLD.account_type = 'conditional'
                        AND NEW.account_type = 'non_conditional'
                        AND current_setting('feriwala.account_type_reconciliation', true) = 'on' THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'orders.account_type is fixed at placement'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER orders_account_type_locked
                BEFORE UPDATE OF account_type ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_account_type_is_locked();
        SQL);
    }

    public function down(): void
    {
        DB::connection()->getPdo()->exec(<<<'SQL'
            DROP TRIGGER IF EXISTS orders_account_type_locked ON orders;
            DROP FUNCTION IF EXISTS feriwala_order_account_type_is_locked();
            CREATE TRIGGER orders_account_type_locked
                BEFORE UPDATE OF account_type ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('account_type');
        SQL);

        DB::connection()->getPdo()->exec(<<<'SQL'
            ALTER TABLE partner_storefront_profits
                DROP CONSTRAINT IF EXISTS partner_storefront_profits_review_hold_dated,
                DROP CONSTRAINT IF EXISTS partner_storefront_profits_review_hold_known;
        SQL);

        Schema::table('partner_storefront_profits', function (Blueprint $table) {
            $table->dropColumn(['review_hold', 'review_hold_at', 'breakdown']);
        });

        Schema::dropIfExists('storefront_reconciliation_items');
        Schema::dropIfExists('storefront_reconciliation_runs');
    }
};
