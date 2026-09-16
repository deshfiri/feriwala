<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website status history (§16.4, P5-9), in the shared shape (P0-16).
 *
 * The same columns every status history keeps — previous status, new status,
 * changed by, when, reason, internal note, public note — plus what moved it.
 * The internal note is for the platform; only the public note is ever shown to
 * the partner whose website it is.
 *
 * **Append-only**, by the shared guard `feriwala_status_history_is_append_only()`.
 * A suspension nobody can rewrite is the point: it is the record of why a
 * storefront went dark.
 */
return new class extends Migration
{
    /**
     * The §16.4 statuses, written out rather than read from the enum: a
     * constraint is a literal the database keeps, and a migration that
     * generated it from application code would change what it created the day
     * somebody edits that code.
     */
    private const STATUSES = [
        'setup_pending', 'deposit_pending', 'development', 'api_connection_pending',
        'active', 'low_wallet_balance', 'grace_period', 'temporarily_disabled',
        'package_expired', 'domain_renewal_pending', 'hosting_renewal_pending',
        'suspended', 'maintenance', 'closed',
    ];

    private const SOURCES = ['account', 'staff', 'scheduler', 'billing', 'integration', 'system'];

    public function up(): void
    {
        Schema::create('website_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->restrictOnDelete();

            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->string('source', 16);

            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();

            $table->index(['website_id', 'id']);
        });

        $statuses = self::quoted(self::STATUSES);
        $sources = self::quoted(self::SOURCES);

        DB::unprepared(<<<SQL
            ALTER TABLE website_status_history
                ADD CONSTRAINT website_status_history_previous_status_known CHECK (
                    previous_status IS NULL OR previous_status IN ({$statuses})
                ),
                ADD CONSTRAINT website_status_history_new_status_known CHECK (new_status IN ({$statuses})),
                ADD CONSTRAINT website_status_history_source_known CHECK (source IN ({$sources})),
                ADD CONSTRAINT website_status_history_is_a_change CHECK (previous_status IS DISTINCT FROM new_status);

            CREATE TRIGGER website_status_history_no_update
                BEFORE UPDATE ON website_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER website_status_history_no_delete
                BEFORE DELETE ON website_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('website_status_history');
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
