<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What Banij is owed on a Store Order that was paid to a Partner's own gateway,
 * frozen when the order is placed (D36).
 *
 * Two tables, both append-only in the database. `order_platform_receivables` is
 * the total, one per order; `order_platform_receivable_lines` is every component
 * that makes it up — product recovery and its tax per order line, then the
 * delivery charge and the tax charged on it. A later price, fee or tax change
 * never reaches either: the figures are copied from the order's own frozen
 * snapshot and the quote it was placed from.
 *
 * The lines are what a later reversal is worked out from. Each carries its
 * component, its quantity, its exact amount, the order line it came from and the
 * rule it followed, so a partial return or a cancellation never has to look at
 * today's prices.
 *
 * Whether the receivable was collected, held or released is a different fact and
 * lives elsewhere; nothing here ever moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_platform_receivables', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('business_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('website_id')->constrained()->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->decimal('total', 19, 2);
            $table->string('calculation_version', 16);
            $table->timestamp('frozen_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('business_account_id');
        });

        Schema::create('order_platform_receivable_lines', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_platform_receivable_id')
                ->constrained('order_platform_receivables', indexName: 'opr_lines_receivable_foreign')
                ->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('component', 24);
            $table->string('component_key', 64);
            $table->string('basis', 32)->nullable();
            $table->string('tax_mode', 16)->nullable();
            $table->string('description', 160);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_amount', 19, 2)->nullable();
            $table->decimal('line_amount', 19, 2);
            $table->char('currency_code', 3);
            $table->string('rule_reference', 96);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['order_platform_receivable_id', 'component_key'], 'opr_lines_component_key_unique');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_platform_receivables
                ADD CONSTRAINT order_platform_receivables_total_not_negative CHECK (total >= 0);

            ALTER TABLE order_platform_receivable_lines
                ADD CONSTRAINT opr_lines_component_known
                    CHECK (component IN ('product_recovery', 'product_tax', 'delivery_charge', 'delivery_tax')),
                ADD CONSTRAINT opr_lines_basis_known
                    CHECK (basis IS NULL OR basis IN ('platform_retained_amount', 'product_cost')),
                ADD CONSTRAINT opr_lines_tax_mode_known
                    CHECK (tax_mode IS NULL OR tax_mode IN ('exclusive', 'inclusive')),
                ADD CONSTRAINT opr_lines_amounts_not_negative
                    CHECK (line_amount >= 0 AND (unit_amount IS NULL OR unit_amount >= 0)),
                ADD CONSTRAINT opr_lines_quantity_positive CHECK (quantity >= 1),
                ADD CONSTRAINT opr_lines_product_components_have_a_line
                    CHECK ((component IN ('product_recovery', 'product_tax')) = (order_item_id IS NOT NULL));

            CREATE TRIGGER order_platform_receivables_no_update
                BEFORE UPDATE ON order_platform_receivables
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
            CREATE TRIGGER order_platform_receivables_no_delete
                BEFORE DELETE ON order_platform_receivables
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
            CREATE TRIGGER order_platform_receivable_lines_no_update
                BEFORE UPDATE ON order_platform_receivable_lines
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
            CREATE TRIGGER order_platform_receivable_lines_no_delete
                BEFORE DELETE ON order_platform_receivable_lines
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS order_platform_receivable_lines_no_update ON order_platform_receivable_lines;
            DROP TRIGGER IF EXISTS order_platform_receivable_lines_no_delete ON order_platform_receivable_lines;
            DROP TRIGGER IF EXISTS order_platform_receivables_no_update ON order_platform_receivables;
            DROP TRIGGER IF EXISTS order_platform_receivables_no_delete ON order_platform_receivables;
        SQL);

        Schema::dropIfExists('order_platform_receivable_lines');
        Schema::dropIfExists('order_platform_receivables');
    }
};
