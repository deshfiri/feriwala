<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A store notification can be deferred (D29).
 *
 * A webhook that names an order Feriwala has not seen, but carries too little
 * to create it safely — no store id, an amount or the items missing — is kept
 * as `deferred` while the pull API brings the order in whole. Additive: the
 * existing states keep their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE external_store_webhook_events
                DROP CONSTRAINT external_store_webhook_events_state_known,
                ADD CONSTRAINT external_store_webhook_events_state_known CHECK (
                    state IN ('received', 'processed', 'ignored', 'failed', 'deferred')
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            UPDATE external_store_webhook_events SET state = 'failed' WHERE state = 'deferred';

            ALTER TABLE external_store_webhook_events
                DROP CONSTRAINT external_store_webhook_events_state_known,
                ADD CONSTRAINT external_store_webhook_events_state_known CHECK (
                    state IN ('received', 'processed', 'ignored', 'failed')
                );
        SQL);
    }
};
