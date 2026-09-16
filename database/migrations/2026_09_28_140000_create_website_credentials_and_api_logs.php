<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a storefront proves who it is, and the record of every call it makes
 * (§17.3, contract §3, §9, P5-17, P5-18, P5-27).
 *
 * **The credential is the tenancy boundary** (contract §3.5). It belongs to
 * exactly one website, and every query the API runs is scoped to that website
 * before anything the caller sent is applied — so there is no column here that
 * could point a credential at a second shop.
 *
 * The secret is stored **encrypted**, never hashed: an HMAC has to be computed
 * with the secret itself, so a one-way hash of it would be a credential nobody
 * could verify with. Encryption at rest is what the contract asks for (§3.1),
 * and the plaintext is shown to a person exactly once, when it is issued.
 *
 * Rotation keeps the previous secret valid for a short, stated window, the same
 * shape the webhook secret uses (§7.2): a storefront cannot redeploy in the same
 * instant a partner presses "rotate", and a rotation that dropped every request
 * in between would be one nobody dares to do.
 *
 * `api_logs` is evidence, written once. It records what was asked and what was
 * answered — the credential, the endpoint, the status, how long it took, the
 * request identifier a support conversation quotes — with signatures, secrets
 * and personal data removed before it is stored (§42).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_credentials', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('website_id')->constrained('websites')->restrictOnDelete();

            $table->string('name', 80);
            $table->string('key_id', 40)->unique();

            // Encrypted at rest (contract §3.1). The last four characters are
            // kept apart so a screen can say which secret is in use without
            // decrypting it.
            $table->text('secret');
            $table->string('secret_hint', 8);
            $table->text('previous_secret')->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();

            $table->jsonb('scopes');

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['website_id', 'revoked_at']);
        });

        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('request_id')->unique();

            // Nullable: a request presenting a key nobody issued has no website
            // and no credential, and is exactly the request worth recording.
            $table->foreignId('website_id')->nullable()->constrained('websites')->nullOnDelete();
            $table->foreignId('website_credential_id')->nullable()->constrained('website_credentials')->nullOnDelete();
            $table->string('key_id', 40)->nullable();

            $table->string('method', 8);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->string('error_code', 64)->nullable();
            $table->string('ip', 45)->nullable();

            // Redacted before storage (§42): no signature, no secret, no
            // unmasked personal data.
            $table->jsonb('request_summary')->nullable();

            $table->timestamp('created_at');

            $table->index(['website_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE website_credentials
                ADD CONSTRAINT website_credentials_key_id_shape CHECK (key_id ~ '^wsk_[0-9A-Z]{26}$'),
                ADD CONSTRAINT website_credentials_scopes_is_a_list CHECK (jsonb_typeof(scopes) = 'array'),
                ADD CONSTRAINT website_credentials_revocation_has_a_reason CHECK (
                    revoked_at IS NULL OR revoked_reason IS NOT NULL
                );

            ALTER TABLE api_logs
                ADD CONSTRAINT api_logs_status_is_http CHECK (status BETWEEN 100 AND 599);

            -- Evidence is written once. A log that can be edited cannot settle
            -- "did you send it?", which is the question it exists to answer.
            CREATE OR REPLACE FUNCTION feriwala_api_logs_are_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'api_logs is append-only: % is not permitted.', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER api_logs_no_update
                BEFORE UPDATE ON api_logs
                FOR EACH ROW EXECUTE FUNCTION feriwala_api_logs_are_append_only();

            CREATE TRIGGER website_credentials_locked_columns
                BEFORE UPDATE OF public_id, website_id, key_id ON website_credentials
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'website_id', 'key_id');
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('api_logs');
        Schema::dropIfExists('website_credentials');

        DB::unprepared('DROP FUNCTION IF EXISTS feriwala_api_logs_are_append_only();');
    }
};
