<?php

use App\Domain\Tax\Enums\TaxMode;
use App\Domain\Tax\Enums\TaxScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The configurable tax engine (D19, §9).
 *
 * Three tables, each answering a different question:
 *
 *   - `tax_rates` — what a named rate is **worth on a given date**. A rate is
 *     versioned rather than edited: when VAT moves from 15% to 12% a new row
 *     opens, and the old one closes. Editing in place would silently rewrite
 *     every historical invoice's arithmetic.
 *   - `tax_rules` — which rate applies to **what**, and from when. Product,
 *     category, fee, or everything.
 *   - `tax_exemptions` — which accounts pay **no** tax, why, and for how long.
 *
 * Rules point at a rate by its **code**, not its row id. A rule pinned to a row
 * would keep charging 15% after the rate changed, which is exactly the bug that
 * versioning the rate was meant to prevent.
 *
 * No statutory rate is created here. D19 is explicit that Bangladesh VAT rates
 * and Mushak requirements are confirmed with an accountant before launch, and a
 * migration that seeded 15% would put a number nobody agreed into production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // The stable name a rule refers to: `vat-standard`, `vat-zero`.
            // Several rows share a code, one per period of validity.
            $table->string('code', 64);
            $table->string('name');

            /*
             * Basis points, not a decimal or a float: 1500 is 15.00%. Money is
             * never a float in this application (D4, §36.1) and a rate is one
             * multiplication away from money — 7.5% held as a float is 0.075000
             * 000000000001, and that lands in a ledger that must reconcile to
             * the poisha.
             */
            $table->unsignedInteger('rate_basis_points');

            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One version of a code may start on a given date. Overlapping
            // windows are refused by the application, which can report which
            // rows clash; a database check could only refuse the write.
            $table->unique(['code', 'effective_from']);
            $table->index(['code', 'effective_from', 'effective_until']);
        });

        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->string('scope', 32)->default(TaxScope::Everything->value);

            /*
             * What the rule targets, as a string rather than a foreign key.
             *
             * For a fee rule it is an AllocationType value. For product and
             * category rules it is the public id of a row in tables that do not
             * exist yet (Phase 2). A nullable FK to a missing table is not
             * available, and inventing those tables early to satisfy one column
             * would be worse than a documented string.
             */
            $table->string('scope_value')->nullable();

            $table->string('tax_code', 64);
            $table->string('mode', 16)->default(TaxMode::Exclusive->value);

            // Breaks ties between rules of the same specificity, never across
            // specificity — see TaxScope.
            $table->unsignedSmallInteger('priority')->default(0);

            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->boolean('is_active')->default(true);

            $table->string('note')->nullable();

            $table->timestamps();

            $table->index(['scope', 'scope_value', 'effective_from']);
            $table->index(['tax_code']);
        });

        Schema::create('tax_exemptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('business_account_id')->constrained()->cascadeOnDelete();

            // Why, and on what authority. An exemption with no certificate
            // reference is a decision nobody can defend to an auditor, so the
            // reason is required even though the reference may not be.
            $table->string('reason');
            $table->string('certificate_reference')->nullable();

            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();

            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['business_account_id', 'effective_from', 'effective_until']);
        });

        /*
         * The tax actually charged, per rate, on one payment (D19).
         *
         * Separate from `payment_allocations`, which carries a single rolled-up
         * Tax line. An invoice has to show "VAT 15% on 5,000 = 750" per rate,
         * and a tax return needs the taxable base as well as the tax — neither
         * is recoverable from a total. The rate and its basis points are copied
         * in rather than referenced, so a later rate change cannot rewrite what
         * an issued invoice says.
         */
        Schema::create('payment_tax_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            $table->string('tax_code', 64);
            $table->string('label');
            $table->unsignedInteger('rate_basis_points');
            $table->string('mode', 16);

            $table->bigInteger('taxable_amount_minor');
            $table->bigInteger('tax_amount_minor');
            $table->string('currency_code', 3);

            $table->timestamps();

            $table->index(['payment_id', 'tax_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_tax_lines');
        Schema::dropIfExists('tax_exemptions');
        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('tax_rates');
    }
};
