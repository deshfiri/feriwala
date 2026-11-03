<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the tax that was actually assessed on a receivable line, apart from what
 * it contributes to the receivable (D36).
 *
 * `line_amount` is what a line adds to the total Banij is owed. For tax that
 * already sits inside the delivery charge it is nothing — adding it again would
 * count it twice — and the earlier shape lost the figure altogether. This keeps
 * it: `assessed_amount` is the tax as it was charged, and `tax_mode` says whether
 * it was added on top or already inside the price, so an explanation, a report or
 * a reversal can still see the real tax without it ever changing the total.
 *
 * Set on the tax components (`product_tax`, `delivery_tax`) and null on the rest.
 * Additive and nullable: a receivable frozen before this column existed is not
 * rewritten, and the table stays append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_platform_receivable_lines', function (Blueprint $table): void {
            $table->decimal('assessed_amount', 19, 2)->nullable()->after('line_amount');
        });
    }

    public function down(): void
    {
        Schema::table('order_platform_receivable_lines', function (Blueprint $table): void {
            $table->dropColumn('assessed_amount');
        });
    }
};
