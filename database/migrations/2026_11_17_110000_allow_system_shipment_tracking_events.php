<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A tracking event can now say it came from Banij's own scheduled status check.
 *
 * `shipment_tracking_events.source` allowed only `manual` and `webhook`. A poll
 * is neither -- recording it as "manual" would credit a person with something
 * nobody did, and as "webhook" would claim Steadfast pushed what Banij fetched --
 * so `system` is added, matching the `system` value shipment status history
 * already accepts. Widens the check only; no row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE shipment_tracking_events
                DROP CONSTRAINT shipment_tracking_events_source_known,
                ADD CONSTRAINT shipment_tracking_events_source_known
                    CHECK (source IN ('manual', 'webhook', 'system'));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE shipment_tracking_events
                DROP CONSTRAINT shipment_tracking_events_source_known,
                ADD CONSTRAINT shipment_tracking_events_source_known
                    CHECK (source IN ('manual', 'webhook'));
        SQL);
    }
};
