<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons and promotional discounts (§9).
 *
 * Two tables because a coupon and its use are different things with different
 * lifetimes. The coupon is configuration — an administrator writes it, dates it
 * and limits it. A redemption is a fact about one account and one payment, and
 * it is what the limits are actually counted from: a counter on the coupon alone
 * cannot say **who** used it, which is what a per-account limit needs.
 *
 * `redeemed_count` is kept beside the rows on purpose. It is the column the row
 * lock is taken on, so two concurrent checkouts serialise on one row rather than
 * on a count that both would read as the same number.
 *
 * A redemption is **reserved** at checkout and **redeemed** at settlement, never
 * at the moment somebody opens a page (§9). Releasing an unpaid one returns the
 * slot — which is why the status is a state and not a boolean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // Compared case-insensitively; stored as typed so an administrator
            // sees back what they wrote.
            $table->string('code', 40)->unique();
            $table->string('name', 191);

            $table->string('discount_type', 20);

            /*
             * Basis points for a percentage, minor units for a fixed amount.
             * One column, because a coupon is one or the other and two nullable
             * columns would allow a row that is both or neither.
             */
            $table->bigInteger('value');
            $table->string('currency_code', 3);

            // Which part of the bill it comes off (§9).
            $table->string('applies_to', 30);

            // Null is every package.
            $table->foreignId('package_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->bigInteger('minimum_spend_minor')->nullable();

            // Caps a percentage coupon in money terms, so "50% off" on a large
            // plan cannot cost more than was budgeted for the promotion.
            $table->bigInteger('maximum_discount_minor')->nullable();

            // Null is unlimited on both.
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_account_limit')->nullable()->default(1);

            // Reserved plus redeemed. A reservation holds a slot, or two
            // checkouts started at once could both spend the last one.
            $table->unsignedInteger('redeemed_count')->default(0);

            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['is_active', 'effective_from']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();

            /*
             * The payment this reservation belongs to. Unique, so a retried
             * checkout submission reserves once — the index settles it rather
             * than a check the second request would also pass.
             */
            $table->foreignId('payment_id')->nullable()->unique()
                ->constrained()->nullOnDelete();

            $table->string('status', 20);

            // What the discount was actually worth, snapshotted: the coupon can
            // be edited afterwards and this must still reconcile with the
            // payment allocation it produced.
            $table->bigInteger('amount_minor');
            $table->string('currency_code', 3);

            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            $table->index(['coupon_id', 'status']);
            $table->index(['business_account_id', 'coupon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
