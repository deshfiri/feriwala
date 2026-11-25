<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Partner's profit on one Storefront order, and its way to their Wallet.
 *
 * Both kinds of Partner earn it. A line sold from Banij's central stock earns
 * its net selling amount less the platform price frozen on it (D34); a line sold
 * from the Partner's own stock earns its net selling amount less what those
 * exact units cost them, first in, first out. Net means after discount and
 * without tax; delivery and other charges are never in it.
 *
 * **One row per order**, by unique index: the order's profit is settled once,
 * under the Wallet key `partner-storefront-profit:{order public id}`. It becomes
 * releasable only when the order is Delivered — at the time the delivery history
 * says — and the money for it has been collected by Banij: cash on delivery
 * confirmed, or Banij's own gateway settled for the order's total. It is then
 * credited as withdrawable money 24 hours after that delivery, by the minute
 * sweep, unless something blocks it by then: a cancellation, a return or damage
 * case still open, or a refund in progress. A profit below zero, or one whose
 * cost cannot be proven, waits for a person. A Partner's own gateway already paid
 * them, so its profit is shown and never credited.
 *
 * The figures are worked out again whenever the order changes, until released;
 * after release, a return takes back the difference by a compensating debit —
 * never into a negative balance: what the Wallet cannot cover is recorded as
 * owed and taken when it can (`partner_storefront_profit_reversals`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_storefront_profits', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('business_account_id')->constrained()->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->string('status', 24);
            $table->string('status_reason', 40)->nullable();

            // `cod`, `platform_gateway` or `partner_gateway_external` (D36); null until known.
            $table->string('collection_channel', 24)->nullable();

            // The two facts, and when the hold ends.
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->decimal('collected_amount', 19, 2)->nullable();
            $table->foreignId('cod_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('release_at')->nullable();

            // The figures, worked out again until released. `profit_amount`
            // may be below zero; it is then never credited.
            $table->decimal('selling_amount', 19, 2)->nullable();
            $table->decimal('cost_amount', 19, 2)->nullable();
            $table->decimal('profit_amount', 19, 2)->nullable();

            // What reached the Wallet, and what was taken back.
            $table->decimal('released_amount', 19, 2)->default(0);
            $table->decimal('reversed_amount', 19, 2)->default(0);
            $table->timestamp('released_at')->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();

            // A credit already posted for this order's lines by the earlier,
            // per-line settlement — the reason this one never credits again.
            $table->decimal('legacy_credited_amount', 19, 2)->default(0);

            // Technical failures only: the retry staff may ask for.
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('failure_message')->nullable();

            $table->timestamps();

            $table->index(['status', 'release_at']);
            $table->index(['business_account_id', 'status']);
        });

        Schema::create('partner_storefront_profit_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_storefront_profit_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->restrictOnDelete();
            $table->char('currency_code', 3);

            // `platform_price` (central stock), `fifo_lots` (the Partner's own),
            // `historical_single_cost` (sold before lots existed, from the one
            // price the Partner ever paid), `finance_correction` (set by staff).
            $table->string('cost_basis', 24);

            $table->integer('quantity');
            $table->integer('returned_quantity')->default(0);
            $table->decimal('selling_amount', 19, 2);
            $table->decimal('cost_amount', 19, 2)->nullable();
            $table->decimal('profit_amount', 19, 2)->nullable();

            // Why the cost could not be proven, when it could not.
            $table->string('unproven_reason', 40)->nullable();

            $table->timestamps();
        });

        Schema::create('partner_storefront_profit_reversals', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('partner_storefront_profit_id')->constrained()->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->decimal('amount', 19, 2);

            // `owed` until the Wallet could carry it, then `posted`.
            $table->string('status', 12);
            $table->string('cause', 24);
            $table->string('idempotency_key', 191)->unique();
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        /*
         * A staff decision on a profit whose cost could not be proven: the lots
         * and quantities chosen, the reason and the reference — kept exactly as
         * decided, never edited.
         */
        Schema::create('partner_storefront_profit_corrections', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('partner_storefront_profit_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->jsonb('allocations');
            $table->decimal('cost_amount', 19, 2);
            $table->text('reason');
            $table->string('reference', 191);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });

        DB::connection()->getPdo()->exec(<<<'SQL'
            ALTER TABLE partner_storefront_profits
                ADD CONSTRAINT partner_storefront_profits_status_known CHECK (status IN (
                    'awaiting_facts', 'holding', 'blocked', 'review_required', 'released',
                    'reversed', 'no_profit', 'reported_only', 'failed', 'cancelled'
                )),
                ADD CONSTRAINT partner_storefront_profits_channel_known CHECK (
                    collection_channel IS NULL OR collection_channel IN ('cod', 'platform_gateway', 'partner_gateway_external')
                ),
                ADD CONSTRAINT partner_storefront_profits_amounts CHECK (
                    released_amount >= 0 AND reversed_amount >= 0 AND reversed_amount <= released_amount
                    AND legacy_credited_amount >= 0
                    AND (collected_amount IS NULL OR collected_amount >= 0)
                ),
                ADD CONSTRAINT partner_storefront_profits_hold_needs_both_facts CHECK (
                    release_at IS NULL OR (delivered_at IS NOT NULL AND collected_at IS NOT NULL)
                ),
                ADD CONSTRAINT partner_storefront_profits_credit_is_released CHECK (
                    (wallet_transaction_id IS NULL) = (released_at IS NULL)
                ),
                ADD CONSTRAINT partner_storefront_profits_partner_gateway_never_credited CHECK (
                    collection_channel IS DISTINCT FROM 'partner_gateway_external' OR wallet_transaction_id IS NULL
                );

            CREATE TRIGGER partner_storefront_profits_credit_locked
                BEFORE UPDATE OF wallet_transaction_id, released_at, released_amount ON partner_storefront_profits
                FOR EACH ROW WHEN (OLD.wallet_transaction_id IS NOT NULL)
                EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('wallet_transaction_id', 'released_at', 'released_amount');

            CREATE TRIGGER partner_storefront_profits_no_delete
                BEFORE DELETE ON partner_storefront_profits
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            ALTER TABLE partner_storefront_profit_lines
                ADD CONSTRAINT partner_storefront_profit_lines_basis_known CHECK (
                    cost_basis IN ('platform_price', 'fifo_lots', 'historical_single_cost', 'finance_correction')
                ),
                ADD CONSTRAINT partner_storefront_profit_lines_quantities CHECK (
                    quantity > 0 AND returned_quantity >= 0 AND returned_quantity <= quantity
                ),
                ADD CONSTRAINT partner_storefront_profit_lines_proven_or_why CHECK (
                    (cost_amount IS NULL) = (unproven_reason IS NOT NULL)
                    AND (cost_amount IS NULL) = (profit_amount IS NULL)
                );

            CREATE TRIGGER partner_storefront_profit_lines_no_delete
                BEFORE DELETE ON partner_storefront_profit_lines
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            ALTER TABLE partner_storefront_profit_reversals
                ADD CONSTRAINT partner_storefront_profit_reversals_status_known CHECK (status IN ('owed', 'posted')),
                ADD CONSTRAINT partner_storefront_profit_reversals_amount_positive CHECK (amount > 0),
                ADD CONSTRAINT partner_storefront_profit_reversals_posted_has_entry CHECK (
                    (status = 'posted') = (wallet_transaction_id IS NOT NULL)
                );

            CREATE TRIGGER partner_storefront_profit_reversals_locked_columns
                BEFORE UPDATE OF public_id, partner_storefront_profit_id, currency_code, amount, cause, idempotency_key
                ON partner_storefront_profit_reversals
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'partner_storefront_profit_id', 'currency_code', 'amount', 'cause', 'idempotency_key'
                );

            CREATE TRIGGER partner_storefront_profit_reversals_no_delete
                BEFORE DELETE ON partner_storefront_profit_reversals
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            ALTER TABLE partner_storefront_profit_corrections
                ADD CONSTRAINT partner_storefront_profit_corrections_cost_not_negative CHECK (cost_amount >= 0);

            CREATE TRIGGER partner_storefront_profit_corrections_no_update
                BEFORE UPDATE ON partner_storefront_profit_corrections
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER partner_storefront_profit_corrections_no_delete
                BEFORE DELETE ON partner_storefront_profit_corrections
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_storefront_profit_corrections');
        Schema::dropIfExists('partner_storefront_profit_reversals');
        Schema::dropIfExists('partner_storefront_profit_lines');
        Schema::dropIfExists('partner_storefront_profits');
    }
};
