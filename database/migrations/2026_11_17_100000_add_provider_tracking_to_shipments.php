<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a real courier API adds to a shipment, kept provider-neutral and
 * additive (D8): nothing here names a provider, and every existing shipment
 * stays exactly as it was.
 *
 * - `shipments` gains the provider's own identifier and its raw status, kept as
 *   a diagnostic snapshot (the application acts on the typed
 *   {@see OrderCourierStatus}, never on this string), and the charge the
 *   provider reports. That charge is its own column and **not**
 *   `delivery_charge`, which is the Partner-facing decision the shipment froze
 *   at creation and the database refuses to change.
 * - `courier_booking_attempts` is the record of asking a provider to take a
 *   parcel. It exists *before* the call, so a timeout leaves something to
 *   reconcile instead of nothing; and it is separate from `shipments` because
 *   a shipment is never deleted, so a failed booking must not create one. At
 *   most one attempt per order can be live (pending, booked or uncertain).
 * - `courier_webhook_receipts` makes a provider's push idempotent: the same
 *   event key from the same provider is processed once. It holds a hash of the
 *   body, never the body, which carries a customer's details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('external_id', 64)->nullable()->after('tracking_number');
            $table->string('provider_status', 64)->nullable()->after('external_id');
            $table->timestamp('provider_status_at')->nullable()->after('provider_status');
            $table->decimal('provider_charge', 19, 2)->nullable()->after('provider_status_at');
            $table->timestamp('provider_synced_at')->nullable()->after('provider_charge');
            $table->string('provider_sync_error', 255)->nullable()->after('provider_synced_at');
            $table->boolean('needs_review')->default(false)->after('provider_sync_error');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE shipments
                ADD CONSTRAINT shipments_provider_charge_not_negative
                    CHECK (provider_charge IS NULL OR provider_charge >= 0);

            -- One provider identifier names one shipment.
            CREATE UNIQUE INDEX shipments_provider_external_id_unique
                ON shipments (courier_provider_id, external_id)
                WHERE external_id IS NOT NULL;

            -- The sweep reads only what is still moving and has been flagged
            -- or synced; it must never scan every historical shipment.
            CREATE INDEX shipments_needs_review_index ON shipments (needs_review) WHERE needs_review;
        SQL);

        Schema::create('courier_booking_attempts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('courier_provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('attempted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 16);
            $table->string('idempotency_key', 64)->unique();

            // What was asked, without credentials and without the customer's
            // full details: enough to explain the booking, not to leak it.
            $table->json('request_snapshot')->nullable();
            $table->string('failure_code', 40)->nullable();
            $table->string('failure_message', 255)->nullable();

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE courier_booking_attempts
                ADD CONSTRAINT courier_booking_attempts_status_known
                    CHECK (status IN ('pending', 'booked', 'failed', 'uncertain')),
                ADD CONSTRAINT courier_booking_attempts_booked_has_a_shipment
                    CHECK (status <> 'booked' OR shipment_id IS NOT NULL);

            -- One live attempt per order, whichever provider it is with: a
            -- retry or a second click finds this one rather than making another.
            CREATE UNIQUE INDEX courier_booking_attempts_one_live_per_order
                ON courier_booking_attempts (order_id)
                WHERE status IN ('pending', 'booked', 'uncertain');
        SQL);

        Schema::create('courier_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('event_key', 128);
            $table->char('payload_hash', 64);
            $table->string('outcome', 24)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['courier_provider_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_webhook_receipts');
        Schema::dropIfExists('courier_booking_attempts');

        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS shipments_needs_review_index;
            DROP INDEX IF EXISTS shipments_provider_external_id_unique;
            ALTER TABLE shipments DROP CONSTRAINT IF EXISTS shipments_provider_charge_not_negative;
        SQL);

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn([
                'external_id', 'provider_status', 'provider_status_at', 'provider_charge',
                'provider_synced_at', 'provider_sync_error', 'needs_review',
            ]);
        });
    }
};
