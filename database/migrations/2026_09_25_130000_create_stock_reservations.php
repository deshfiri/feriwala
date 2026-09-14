<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock set aside for an order that is not yet confirmed (§19.1, P3-25).
 *
 * The frozen storefront contract (§6.1.2) fixes the shape: every reservation
 * carries a stored `expires_at`, reserving and releasing are idempotent, and
 * overselling is never permitted under any race, retry or override. A
 * reservation holds units in the item's reserved bucket; it ends exactly once —
 * committed to the confirmed order, released, or expired — and the database
 * holds the status and its timestamps consistent with each other.
 *
 * What a reservation is for — which item, how many, what kind, which reference —
 * never changes once written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            $table->integer('quantity');

            // §6.1.2's two order types, with their own windows.
            $table->string('kind', 20);
            $table->string('status', 20)->default('active');

            // What the reservation answers to — the order, when orders exist
            // (P4-10, P5). Unique, which is what makes reserving idempotent.
            $table->string('reference', 120)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();

            // Set when a person ended or extended it by hand (P3-26).
            $table->foreignId('overridden_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            // The expiry sweep reads exactly this.
            $table->index(['status', 'expires_at']);
            $table->index(['stock_item_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_reservations
                ADD CONSTRAINT stock_reservations_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT stock_reservations_kind_known CHECK (kind IN ('online_payment', 'cod')),
                ADD CONSTRAINT stock_reservations_status_known CHECK (status IN ('active', 'committed', 'released', 'expired')),
                -- The status and its timestamps tell one story.
                ADD CONSTRAINT stock_reservations_status_consistent CHECK (
                    (status = 'active' AND committed_at IS NULL AND released_at IS NULL)
                    OR (status = 'committed' AND committed_at IS NOT NULL AND released_at IS NULL)
                    OR (status IN ('released', 'expired') AND released_at IS NOT NULL AND committed_at IS NULL)
                );

            CREATE TRIGGER stock_reservations_locked_columns
                BEFORE UPDATE OF public_id, stock_item_id, quantity, kind, reference ON stock_reservations
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'stock_item_id', 'quantity', 'kind', 'reference');
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
