<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Raw tracking events a shipment has recorded (Advanced Order Management
 * batch, Commit 5) -- distinct from `shipment_status_history`: a status
 * change is always one of the shipment's own legal moves, while a tracking
 * event is whatever the source actually said, which may describe the same
 * status change in more words or add no status change at all (a scan at a
 * hub, say). Append-only, like every other event log in this application.
 *
 * `external_event_id` is the webhook-idempotency key for a provider that
 * posts the same event twice -- unique only when present, since a
 * manually-entered event has no external id to deduplicate against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();

            $table->string('event_code', 64);
            $table->text('description')->nullable();
            $table->timestamp('occurred_at');
            $table->json('raw_payload')->nullable();
            $table->string('source', 16);
            $table->string('external_event_id', 128)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['shipment_id', 'occurred_at']);
            $table->unique('external_event_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE shipment_tracking_events
                ADD CONSTRAINT shipment_tracking_events_source_known CHECK (
                    source IN ('manual', 'webhook')
                );

            CREATE TRIGGER shipment_tracking_events_no_update
                BEFORE UPDATE ON shipment_tracking_events
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER shipment_tracking_events_no_delete
                BEFORE DELETE ON shipment_tracking_events
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_tracking_events');
    }
};
