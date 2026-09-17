<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Telling a storefront something changed, and proving it was told
 * (contract §7, §17.2, §17.3, P5-20, P5-22, P5-25–P5-27).
 *
 *   - `website_webhook_endpoints` — **one active endpoint per website** in v1
 *     (§7.2), with its own encrypted signing secret and a previous secret that
 *     keeps verifying for a window after rotation.
 *   - `webhook_deliveries` — the delivery record §7.4 lists, one row per event:
 *     a stable `event_id` that is the storefront's deduplication key across
 *     every retry, the attempt count, the state, and when the next attempt is
 *     due. Retries are picked up by the scheduler from `next_retry_at`, so a
 *     worker restarting mid-backoff loses nothing.
 *   - `webhook_logs` — every attempt as it was answered, redacted, written once.
 *   - `sync_queue_failures` — the dead-letter queue (§17.3). What exhausted its
 *     retries lands here for a person to inspect and retry, and stays as the
 *     record of what went wrong after it is resolved.
 *
 * `website_products.synced_availability` is what each storefront was last told
 * about stock, so the near-real-time pass sends a change rather than every
 * figure every few minutes.
 */
return new class extends Migration
{
    private const DELIVERY_STATES = ['pending', 'delivered', 'retrying', 'failed'];

    public function up(): void
    {
        Schema::create('website_webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // One endpoint per website (contract §7.2).
            $table->foreignId('website_id')->unique()->constrained('websites')->restrictOnDelete();

            $table->string('url', 500);
            $table->text('secret');
            $table->string('secret_hint', 8);
            $table->text('previous_secret')->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('rotated_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();

            // X-Feriwala-Delivery: stable across retries, the consumer's
            // deduplication key (contract §7.3, §7.4).
            $table->ulid('event_id')->unique();

            $table->foreignId('website_id')->constrained('websites')->restrictOnDelete();
            $table->foreignId('website_webhook_endpoint_id')->nullable()
                ->constrained('website_webhook_endpoints')->nullOnDelete();

            $table->string('event_type', 64);
            $table->unsignedSmallInteger('payload_version')->default(1);
            $table->jsonb('payload');

            // What the event is about, so a burst of edits to one product
            // coalesces into one pending delivery rather than twenty.
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('state', 16)->default('pending');
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->foreignId('retried_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retried_at')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'id']);
            $table->index(['state', 'next_retry_at']);
            $table->index(['website_id', 'event_type', 'subject_type', 'subject_id', 'state'], 'webhook_deliveries_coalesce_index');
        });

        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_delivery_id')->constrained('webhook_deliveries')->restrictOnDelete();
            $table->foreignId('website_id')->constrained('websites')->restrictOnDelete();

            $table->unsignedSmallInteger('attempt');
            $table->string('url', 500);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('duration_ms');

            // Redacted before storage: never the signature, never the secret.
            $table->jsonb('request_headers');
            $table->text('response_excerpt')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('created_at');

            $table->index(['website_id', 'created_at']);
        });

        Schema::create('sync_queue_failures', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('website_id')->constrained('websites')->restrictOnDelete();

            $table->string('kind', 32);
            $table->foreignId('webhook_delivery_id')->nullable()->constrained('webhook_deliveries')->nullOnDelete();

            $table->text('error');
            $table->unsignedSmallInteger('attempts');
            $table->timestamp('failed_at');

            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('last_retried_at')->nullable();
            $table->foreignId('last_retried_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['website_id', 'resolved_at']);
        });

        Schema::table('website_products', function (Blueprint $table) {
            $table->jsonb('synced_availability')->nullable();
        });

        $states = self::quoted(self::DELIVERY_STATES);

        DB::unprepared(<<<SQL
            ALTER TABLE website_webhook_endpoints
                ADD CONSTRAINT website_webhook_endpoints_url_is_https CHECK (url LIKE 'https://%');

            ALTER TABLE webhook_deliveries
                ADD CONSTRAINT webhook_deliveries_state_known CHECK (state IN ({$states})),
                -- The first attempt and the contract's eight retries (§7.3).
                ADD CONSTRAINT webhook_deliveries_attempts_bounded CHECK (attempt <= 9),
                ADD CONSTRAINT webhook_deliveries_retry_has_a_time CHECK (
                    state <> 'retrying' OR next_retry_at IS NOT NULL
                );

            CREATE OR REPLACE FUNCTION feriwala_webhook_logs_are_append_only()
            RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION 'webhook_logs is append-only: % is not permitted.', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER webhook_logs_no_update
                BEFORE UPDATE ON webhook_logs
                FOR EACH ROW EXECUTE FUNCTION feriwala_webhook_logs_are_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::table('website_products', function (Blueprint $table) {
            $table->dropColumn('synced_availability');
        });

        Schema::dropIfExists('sync_queue_failures');
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('website_webhook_endpoints');

        DB::unprepared('DROP FUNCTION IF EXISTS feriwala_webhook_logs_are_append_only();');
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
