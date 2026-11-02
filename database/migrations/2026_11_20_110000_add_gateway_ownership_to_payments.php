<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who a payment's money was collected for, frozen with the payment (D36).
 *
 * `gateway_ownership` says whose gateway the customer paid: `platform` (Feriwala
 * is merchant of record, Model A) or `partner` (the Partner is the payment
 * recipient, Model B). `funds_destination` says where the collected money went.
 * `website_id` is the Storefront it was taken for, and
 * `partner_gateway_configuration_version_id` is the exact credential version a
 * Partner-owned payment must be verified and reconciled with.
 *
 * Stamped once, when the payment is handed to a gateway, and never changed:
 * ownership is never inferred later from the Storefront's current settings.
 *
 * Payments from before this have none of the four and are deliberately not
 * backfilled. NULL means "recorded before ownership was frozen", which is the
 * existing platform behaviour, and nothing reclassifies it.
 *
 * The CHECK ties the four together, so a Partner-owned payment cannot exist
 * without the version that settles it, and a platform one cannot carry one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('gateway_ownership', 16)->nullable()->after('gateway_mode');
            $table->string('funds_destination', 16)->nullable()->after('gateway_ownership');
            $table->foreignId('website_id')->nullable()->after('funds_destination')
                ->constrained()->restrictOnDelete();
            $table->foreignId('partner_gateway_configuration_version_id')->nullable()->after('website_id')
                ->constrained('partner_gateway_configuration_versions', indexName: 'payments_partner_gateway_version_foreign')
                ->restrictOnDelete();

            $table->index('gateway_ownership');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payments
                ADD CONSTRAINT payments_gateway_ownership_consistent
                CHECK (
                    (gateway_ownership IS NULL
                        AND funds_destination IS NULL
                        AND website_id IS NULL
                        AND partner_gateway_configuration_version_id IS NULL)
                    OR (gateway_ownership = 'platform'
                        AND funds_destination = 'platform'
                        AND website_id IS NOT NULL
                        AND partner_gateway_configuration_version_id IS NULL)
                    OR (gateway_ownership = 'partner'
                        AND funds_destination = 'partner'
                        AND website_id IS NOT NULL
                        AND partner_gateway_configuration_version_id IS NOT NULL)
                );

            CREATE OR REPLACE FUNCTION feriwala_payment_gateway_ownership_is_set_once()
            RETURNS trigger LANGUAGE plpgsql AS $function$
            BEGIN
                IF OLD.gateway_ownership IS NOT NULL AND (
                    NEW.gateway_ownership IS DISTINCT FROM OLD.gateway_ownership
                    OR NEW.funds_destination IS DISTINCT FROM OLD.funds_destination
                    OR NEW.website_id IS DISTINCT FROM OLD.website_id
                    OR NEW.partner_gateway_configuration_version_id IS DISTINCT FROM OLD.partner_gateway_configuration_version_id
                ) THEN
                    RAISE EXCEPTION 'payments % gateway ownership cannot be changed once written', OLD.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $function$;

            CREATE TRIGGER payments_gateway_ownership_set_once
                BEFORE UPDATE OF gateway_ownership, funds_destination, website_id, partner_gateway_configuration_version_id ON payments
                FOR EACH ROW EXECUTE FUNCTION feriwala_payment_gateway_ownership_is_set_once();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS payments_gateway_ownership_set_once ON payments;
            DROP FUNCTION IF EXISTS feriwala_payment_gateway_ownership_is_set_once();
            ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_gateway_ownership_consistent;
        SQL);

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('partner_gateway_configuration_version_id');
            $table->dropConstrainedForeignId('website_id');
            $table->dropIndex(['gateway_ownership']);
            $table->dropColumn(['gateway_ownership', 'funds_destination']);
        });
    }
};
