<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order status history (§18.3, P6-6).
 *
 * Every status change stores what §18.3 lists — previous status, new status,
 * changed by, date and time, reason, internal note, user-visible note,
 * notification status — in the shared columns of the status-history contract
 * (P0-16), plus what moved it.
 *
 * **Append-only**, by the shared guard `feriwala_status_history_is_append_only()`.
 * Nothing that points at a history row is allowed to rewrite it either: the
 * person who made a change is kept rather than nulled if that user is ever
 * removed, because nulling it would be an update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();

            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40);
            $table->foreign('previous_status')->references('code')->on('order_statuses')->restrictOnDelete();
            $table->foreign('new_status')->references('code')->on('order_statuses')->restrictOnDelete();

            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->string('source', 32);

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();
            $table->string('notification_status', 16);

            $table->index(['order_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_status_history
                ADD CONSTRAINT order_status_history_source_known CHECK (
                    source IN ('checkout', 'payment_gateway', 'scheduler', 'account', 'staff', 'system')
                ),
                ADD CONSTRAINT order_status_history_notification_status_known CHECK (
                    notification_status IN ('not_required', 'queued')
                ),
                ADD CONSTRAINT order_status_history_is_a_change CHECK (previous_status IS DISTINCT FROM new_status);

            CREATE TRIGGER order_status_history_no_update
                BEFORE UPDATE ON order_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER order_status_history_no_delete
                BEFORE DELETE ON order_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
