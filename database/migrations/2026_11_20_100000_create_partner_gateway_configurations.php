<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Partner's own payment gateway for one of their Storefronts (D36, Model B).
 *
 * Two tables, because a payment must be settled and reconciled against the exact
 * credentials it was started with, never against whatever the Storefront holds
 * today:
 *
 *   - `partner_gateway_configurations` is the choice itself: which provider a
 *     Storefront uses and whether it is switched on. One per Storefront.
 *   - `partner_gateway_configuration_versions` is append-only. Every save writes a
 *     new version holding the whole credential set, encrypted. A payment points at
 *     a version and never at the configuration, so a later save, a mode change or
 *     a switch back to the Platform gateway cannot reach an attempt already made.
 *
 * Credentials live only in `credentials`, as the application's own ciphertext.
 * They are never copied to a payment row, a log, an audit entry or a snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_gateway_configurations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('business_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('website_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique('website_id');
            $table->index(['business_account_id', 'status']);
        });

        Schema::create('partner_gateway_configuration_versions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('partner_gateway_configuration_id')
                ->constrained('partner_gateway_configurations', indexName: 'pgcv_configuration_foreign')
                ->restrictOnDelete();
            $table->foreignId('business_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('website_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('provider', 32);
            $table->string('mode', 16);
            $table->text('credentials');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['partner_gateway_configuration_id', 'version'], 'pgcv_configuration_version_unique');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE partner_gateway_configurations
                ADD CONSTRAINT partner_gateway_configurations_status_known
                CHECK (status IN ('active', 'disabled'));

            ALTER TABLE partner_gateway_configuration_versions
                ADD CONSTRAINT partner_gateway_configuration_versions_mode_known
                CHECK (mode IN ('sandbox', 'live'));

            CREATE TRIGGER partner_gateway_configurations_locked
                BEFORE UPDATE OF public_id, business_account_id, website_id ON partner_gateway_configurations
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'business_account_id', 'website_id');

            CREATE TRIGGER partner_gateway_configuration_versions_no_update
                BEFORE UPDATE ON partner_gateway_configuration_versions
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER partner_gateway_configuration_versions_no_delete
                BEFORE DELETE ON partner_gateway_configuration_versions
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS partner_gateway_configuration_versions_no_update ON partner_gateway_configuration_versions;
            DROP TRIGGER IF EXISTS partner_gateway_configuration_versions_no_delete ON partner_gateway_configuration_versions;
            DROP TRIGGER IF EXISTS partner_gateway_configurations_locked ON partner_gateway_configurations;
        SQL);

        Schema::dropIfExists('partner_gateway_configuration_versions');
        Schema::dropIfExists('partner_gateway_configurations');
    }
};
