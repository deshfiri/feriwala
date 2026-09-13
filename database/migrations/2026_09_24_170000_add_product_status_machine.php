<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The product lifecycle (§11.2), its history, and when a product first went
 * live.
 *
 * `products.status` holds only the seven lifecycle statuses, by CHECK, so the
 * channel statuses (§11.2's Dropshipping and Wholesale Enabled/Disabled) cannot
 * be written into the wrong column by anything that bypasses the enum.
 *
 * `published_at` is the first moment a product became Active, and is never moved
 * afterwards — the storefront contract carries it, and "when did this first go
 * on sale" does not change because a product was paused and resumed.
 *
 * The history is append-only in the database, like every other record of a
 * decision in this application: a status history that can be edited cannot
 * settle an argument about what was on sale when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('status');
        });

        Schema::create('product_status_history', function (Blueprint $table) {
            $table->id();

            // Restricted: a product with a history is never removed — it is
            // archived, and its history is the reason.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // Which of §11.2's three axes moved: lifecycle, dropshipping, wholesale.
            $table->string('axis', 20);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                ADD CONSTRAINT products_status_is_lifecycle CHECK (status IN (
                    'draft', 'pending_review', 'active', 'inactive',
                    'out_of_stock', 'discontinued', 'archived'
                ));

            CREATE TRIGGER product_status_history_no_update
                BEFORE UPDATE ON product_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER product_status_history_no_delete
                BEFORE DELETE ON product_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS product_status_history_no_update ON product_status_history;
            DROP TRIGGER IF EXISTS product_status_history_no_delete ON product_status_history;
            ALTER TABLE products DROP CONSTRAINT IF EXISTS products_status_is_lifecycle;
        SQL);

        Schema::dropIfExists('product_status_history');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
