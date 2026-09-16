<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What one storefront sells, how it is arranged, and what it may charge
 * (§15, §15.1, P5-2–P5-6).
 *
 * Three tables, and the split is the point:
 *
 *   - `website_categories` is the partner's **own** arrangement of their shop.
 *     The central catalogue's categories are Feriwala's (§11.3) and a partner
 *     cannot write them, so a shop that wants "Eid collection" needs somewhere
 *     of its own to put it.
 *   - `website_products` is a **selection** of central products, never a copy
 *     of one. It carries only what §15.1 lets a partner decide — visibility,
 *     placement, order, featured, their own price, a promotion and its words.
 *     Nothing about the product itself is here; that stays in the catalogue,
 *     where only Feriwala writes it (§12).
 *   - `website_product_price_rules` is the administrator's half of §15.1:
 *     whether a partner may price at all, the bounds, the allowed margin and
 *     the fields that are locked.
 *
 * The selling price is stored in minor units like every other amount, and the
 * bounds are checked server-side on every write — a price the browser sent is
 * never the price that is kept.
 */
return new class extends Migration
{
    /** §15's publication states. */
    private const PRODUCT_STATUSES = ['selected', 'published', 'unpublished'];

    /** §17.2's synchronisation states. */
    private const SYNC_STATUSES = ['pending', 'synced', 'failed'];

    public function up(): void
    {
        Schema::create('website_categories', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('slug', 140);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One arrangement per shop: two categories on the same storefront
            // cannot share an address, and two shops may both have "Panjabi".
            $table->unique(['website_id', 'slug']);
            $table->index(['website_id', 'position']);
        });

        Schema::create('website_products', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();

            // Denormalised so a partner's own selections can be counted and
            // scoped without joining through the website on every query.
            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();

            // Restricted, not cascaded: a central product is archived rather
            // than deleted (§11.2), and a selection pointing nowhere would be a
            // storefront listing something that cannot be ordered.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->foreignId('website_category_id')->nullable()
                ->constrained('website_categories')->nullOnDelete();

            $table->string('status', 16)->default('selected');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('display_order')->default(0);

            // What the partner charges, within the administrator's bounds (§15.1).
            $table->char('currency_code', 3)->default('BDT');
            $table->bigInteger('price_minor')->nullable();
            $table->bigInteger('promotional_price_minor')->nullable();
            $table->string('promo_title', 120)->nullable();
            $table->text('marketing_description')->nullable();

            // Where the storefront's copy stands (§17.2, P5-7).
            $table->string('sync_status', 16)->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('sync_error')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One selection per product per storefront. Choosing it twice is
            // the same choice.
            $table->unique(['website_id', 'product_id']);
            $table->index(['website_id', 'status']);
            $table->index(['business_account_id', 'status']);
        });

        Schema::create('website_product_price_rules', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // Both null is the global rule; either narrows it (§15.1).
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('packages')->cascadeOnDelete();

            $table->boolean('allows_user_pricing')->default(true);

            $table->char('currency_code', 3)->default('BDT');
            $table->bigInteger('min_price_minor')->nullable();
            $table->bigInteger('max_price_minor')->nullable();
            $table->bigInteger('suggested_price_minor')->nullable();

            // Percent above the floor, whole numbers. A partner pricing at more
            // than this is refused (§15.1).
            $table->unsignedInteger('max_margin_percent')->nullable();

            /*
             * Which of §15.1's settings the partner may touch. Locked wins over
             * editable, and an empty locked list means "everything §15.1
             * allows" — a rule that forgets to say restricts nothing rather
             * than freezing a shop by omission.
             */
            $table->jsonb('locked_fields')->default(DB::raw("'[]'::jsonb"));

            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'package_id', 'effective_from']);
        });

        $productStatuses = self::quoted(self::PRODUCT_STATUSES);
        $syncStatuses = self::quoted(self::SYNC_STATUSES);

        DB::unprepared(<<<SQL
            ALTER TABLE website_products
                ADD CONSTRAINT website_products_status_known CHECK (status IN ({$productStatuses})),
                ADD CONSTRAINT website_products_sync_status_known CHECK (sync_status IN ({$syncStatuses})),
                ADD CONSTRAINT website_products_prices_not_negative CHECK (
                    (price_minor IS NULL OR price_minor >= 0)
                    AND (promotional_price_minor IS NULL OR promotional_price_minor >= 0)
                ),
                -- A promotion is a reduction. One that is not is a price rise
                -- wearing a sale badge.
                ADD CONSTRAINT website_products_promotion_is_lower CHECK (
                    promotional_price_minor IS NULL
                    OR (price_minor IS NOT NULL AND promotional_price_minor < price_minor)
                ),
                -- Nothing is published without a price somebody set.
                ADD CONSTRAINT website_products_published_has_a_price CHECK (
                    status <> 'published' OR (price_minor IS NOT NULL AND published_at IS NOT NULL)
                );

            ALTER TABLE website_product_price_rules
                ADD CONSTRAINT website_product_price_rules_bounds_are_ordered CHECK (
                    min_price_minor IS NULL OR max_price_minor IS NULL OR max_price_minor >= min_price_minor
                ),
                ADD CONSTRAINT website_product_price_rules_amounts_not_negative CHECK (
                    (min_price_minor IS NULL OR min_price_minor >= 0)
                    AND (max_price_minor IS NULL OR max_price_minor >= 0)
                    AND (suggested_price_minor IS NULL OR suggested_price_minor >= 0)
                ),
                ADD CONSTRAINT website_product_price_rules_window_is_ordered CHECK (
                    effective_to IS NULL OR effective_to > effective_from
                );

            CREATE TRIGGER website_products_locked_columns
                BEFORE UPDATE OF public_id, website_id, business_account_id, product_id ON website_products
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'website_id', 'business_account_id', 'product_id'
                );
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('website_product_price_rules');
        Schema::dropIfExists('website_products');
        Schema::dropIfExists('website_categories');
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
