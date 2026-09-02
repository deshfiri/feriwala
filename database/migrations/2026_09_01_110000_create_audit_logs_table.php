<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log — append-only record of every sensitive action (§36.2).
 *
 * Immutability is enforced in three places, because any one of them can be
 * bypassed: the model guards against update and delete, the permission catalogue
 * offers no `audit.edit` or `audit.delete`, and the database triggers below
 * refuse the write even from raw SQL or a future careless migration.
 *
 * §36.2 is explicit that ordinary administrative users must not edit or delete
 * audit logs. A log that can be quietly altered is worse than no log, because it
 * still looks authoritative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // Who. Nullable because the system itself acts — scheduled jobs,
            // gateway callbacks — and those actions must still be recorded.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 32)->default('user');
            $table->string('actor_label')->nullable();

            // What.
            $table->string('action', 64);
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            // The change itself. Values are redacted before they reach here —
            // passwords, tokens, and gateway secrets must never be written (§42).
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            // Why. §32.2 requires a reason on sensitive actions.
            $table->text('reason')->nullable();
            $table->text('note')->nullable();

            // Where from.
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 512)->nullable();

            // Context that makes an entry findable during an investigation.
            $table->foreignId('account_id')->nullable();
            $table->string('module', 32)->nullable();
            $table->boolean('is_sensitive')->default(false);

            // No updated_at: a row that can never change has nothing to update.
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['module', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
            $table->index('is_sensitive');
        });

        // Database-level immutability. The model guard covers Eloquent; this
        // covers everything else.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_audit_logs_immutable()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    'audit_logs is append-only: % is not permitted (requirements.txt 36.2)',
                    TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION feriwala_audit_logs_immutable();

            CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION feriwala_audit_logs_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs;
            DROP TRIGGER IF EXISTS audit_logs_no_delete ON audit_logs;
            DROP FUNCTION IF EXISTS feriwala_audit_logs_immutable();
        SQL);

        Schema::dropIfExists('audit_logs');
    }
};
