<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which way a line's money was collected, so a verified online payment is a
 * fact of its own and not a pretend cash-on-delivery (D36).
 *
 * Until now the only collection fact was `cod_collected_at`, entered by staff, so
 * an online-paid Store Order's profit never settled unless somebody typed an
 * amount against a payment that was not cash. This adds the other channels
 * beside it, and leaves the cash-on-delivery columns exactly as they are:
 *
 *   - `cod` — staff confirm the cash collected (the existing facts);
 *   - `platform_gateway` — the customer paid Feriwala's gateway and the payment
 *     was verified: the line's share of that payment is the collection fact;
 *   - `partner_gateway_external` — the customer paid the Partner's own gateway.
 *     The Partner already holds the money, so the row records the fact for
 *     reporting and can never carry a wallet credit or an earning.
 *
 * A row from before this has no channel, and none is guessed: NULL with a
 * `cod_collected_at` is the existing cash-on-delivery row, untouched.
 *
 * The eligibility CHECK is recreated, not edited in place in the earlier
 * migration: a line is eligible when it is delivered **and** collected by either
 * channel. Everything else about the earlier constraints stands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_proceeds_settlements', function (Blueprint $table): void {
            $table->string('collection_channel', 24)->nullable()->after('cod_amount_collected');
            $table->timestamp('payment_collected_at')->nullable()->after('collection_channel');
            $table->decimal('payment_collected_amount', 19, 2)->nullable()->after('payment_collected_at');
            $table->foreignId('collection_payment_id')->nullable()->after('payment_collected_amount')
                ->constrained('payments')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_proceeds_settlements
                DROP CONSTRAINT order_proceeds_eligibility_needs_both_facts;

            ALTER TABLE order_proceeds_settlements
                ADD CONSTRAINT order_proceeds_eligibility_needs_both_facts CHECK (
                    eligible_at IS NULL
                    OR (delivered_at IS NOT NULL AND (cod_collected_at IS NOT NULL OR payment_collected_at IS NOT NULL))
                ),
                ADD CONSTRAINT order_proceeds_collection_channel_known CHECK (
                    collection_channel IS NULL
                    OR collection_channel IN ('cod', 'platform_gateway', 'partner_gateway_external')
                ),
                ADD CONSTRAINT order_proceeds_payment_collection_is_paired CHECK (
                    (payment_collected_at IS NULL) = (payment_collected_amount IS NULL)
                    AND (payment_collected_at IS NULL) = (collection_payment_id IS NULL)
                ),
                ADD CONSTRAINT order_proceeds_payment_collected_not_negative CHECK (
                    payment_collected_amount IS NULL OR payment_collected_amount >= 0
                ),
                ADD CONSTRAINT order_proceeds_one_collection_fact CHECK (
                    cod_collected_at IS NULL OR payment_collected_at IS NULL
                ),
                ADD CONSTRAINT order_proceeds_channel_matches_its_fact CHECK (
                    (collection_channel IS NULL AND payment_collected_at IS NULL)
                    OR (collection_channel = 'cod' AND payment_collected_at IS NULL)
                    OR (collection_channel IN ('platform_gateway', 'partner_gateway_external')
                        AND payment_collected_at IS NOT NULL AND cod_collected_at IS NULL)
                ),
                ADD CONSTRAINT order_proceeds_external_collection_credits_nothing CHECK (
                    collection_channel IS DISTINCT FROM 'partner_gateway_external'
                    OR (wallet_transaction_id IS NULL AND (reseller_earning IS NULL OR reseller_earning = 0))
                );

            CREATE OR REPLACE FUNCTION feriwala_order_proceeds_collection_is_set_once()
            RETURNS trigger LANGUAGE plpgsql AS $function$
            BEGIN
                IF (OLD.collection_channel IS NOT NULL AND NEW.collection_channel IS DISTINCT FROM OLD.collection_channel)
                    OR (OLD.payment_collected_at IS NOT NULL AND NEW.payment_collected_at IS DISTINCT FROM OLD.payment_collected_at)
                    OR (OLD.payment_collected_amount IS NOT NULL AND NEW.payment_collected_amount IS DISTINCT FROM OLD.payment_collected_amount)
                    OR (OLD.collection_payment_id IS NOT NULL AND NEW.collection_payment_id IS DISTINCT FROM OLD.collection_payment_id)
                THEN
                    RAISE EXCEPTION 'order_proceeds_settlements % collection fact cannot be changed once written', OLD.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $function$;

            CREATE TRIGGER order_proceeds_settlements_collection_set_once
                BEFORE UPDATE OF collection_channel, payment_collected_at, payment_collected_amount, collection_payment_id
                ON order_proceeds_settlements
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_proceeds_collection_is_set_once();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS order_proceeds_settlements_collection_set_once ON order_proceeds_settlements;
            DROP FUNCTION IF EXISTS feriwala_order_proceeds_collection_is_set_once();

            ALTER TABLE order_proceeds_settlements
                DROP CONSTRAINT IF EXISTS order_proceeds_external_collection_credits_nothing,
                DROP CONSTRAINT IF EXISTS order_proceeds_channel_matches_its_fact,
                DROP CONSTRAINT IF EXISTS order_proceeds_one_collection_fact,
                DROP CONSTRAINT IF EXISTS order_proceeds_payment_collected_not_negative,
                DROP CONSTRAINT IF EXISTS order_proceeds_payment_collection_is_paired,
                DROP CONSTRAINT IF EXISTS order_proceeds_collection_channel_known,
                DROP CONSTRAINT IF EXISTS order_proceeds_eligibility_needs_both_facts;

            ALTER TABLE order_proceeds_settlements
                ADD CONSTRAINT order_proceeds_eligibility_needs_both_facts CHECK (
                    eligible_at IS NULL OR (delivered_at IS NOT NULL AND cod_collected_at IS NOT NULL)
                );
        SQL);

        Schema::table('order_proceeds_settlements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('collection_payment_id');
            $table->dropColumn(['collection_channel', 'payment_collected_at', 'payment_collected_amount']);
        });
    }
};
