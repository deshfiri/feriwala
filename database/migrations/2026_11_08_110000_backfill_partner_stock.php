<?php

use App\Domain\Inventory\Actions\BackfillPartnerStock;
use Illuminate\Database\Migrations\Migration;

/**
 * Credit the Partner stock earlier paid wholesale purchases bought (D34).
 *
 * Idempotent and additive: each order line is credited once, keyed by the line,
 * so re-running it — or running it after payments have credited their own lines
 * — changes nothing. Reversing it deliberately does nothing: ledger rows are
 * never deleted, and the tables themselves are dropped by the migration before.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(BackfillPartnerStock::class)->handle();
    }

    public function down(): void
    {
        // The ledger is append-only; the previous migration drops it whole.
    }
};
