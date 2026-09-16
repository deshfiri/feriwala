<?php

use App\Domain\Website\Enums\WebsiteChargeStatus;
use App\Domain\Website\Enums\WebsiteServiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Domains, hosting terms, and what a website is charged for (§16.2, §41, P5-10, P5-11).
 *
 * Domains and hosting are **provisioned by hand** (D9): a partner asks and
 * pays, and somebody at Feriwala registers it. So each row carries the term it
 * was bought for and the date it runs out, which is what the renewal reminders
 * and the two "renewal pending" website statuses are read from.
 *
 * `website_charges` is the money side, kept separate from the services because
 * a charge outlives what it paid for: a hosting term can be cancelled, and the
 * record that it was charged for and paid must not go with it. A charge is
 * settled by a wallet debit and never by editing this row's status alone —
 * `wallet_transaction_id` is what a paid charge points at, and the unique index
 * on it means one debit can never settle two charges.
 */
return new class extends Migration
{
    /**
     * Written out rather than read from the enums: a constraint is a literal
     * the database keeps, and the drift between the two is asserted in the
     * website tests instead.
     */
    private const SERVICE_STATUSES = ['pending', 'active', 'expired', 'cancelled'];

    private const CHARGE_TYPES = ['setup', 'domain', 'hosting', 'maintenance'];

    private const CHARGE_STATUSES = ['due', 'paid', 'waived', 'cancelled'];

    public function up(): void
    {
        Schema::create('website_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->restrictOnDelete();

            $table->string('domain', 253);
            $table->string('registrar', 120)->nullable();
            $table->string('status', 16)->default(WebsiteServiceStatus::Pending->value);

            $table->char('currency_code', 3)->default('BDT');
            $table->bigInteger('fee_minor')->default(0);

            $table->timestamp('registered_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('auto_renew')->default(false);

            // What the partner has already been told, so a daily sweep does not
            // send the same warning every day for a month (§41).
            $table->timestamp('reminded_at')->nullable();
            $table->unsignedSmallInteger('reminder_stage')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['website_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('website_hostings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->restrictOnDelete();

            $table->string('plan', 120);
            $table->string('provider', 120)->nullable();
            $table->string('status', 16)->default(WebsiteServiceStatus::Pending->value);

            $table->char('currency_code', 3)->default('BDT');
            $table->bigInteger('fee_minor')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('auto_renew')->default(false);

            $table->timestamp('reminded_at')->nullable();
            $table->unsignedSmallInteger('reminder_stage')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['website_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('website_charges', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('website_id')->constrained('websites')->restrictOnDelete();
            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();

            $table->string('type', 16);
            $table->string('status', 16)->default(WebsiteChargeStatus::Due->value);

            $table->char('currency_code', 3)->default('BDT');
            $table->bigInteger('amount_minor');

            // The term a recurring charge covers, so two years of hosting are
            // two rows rather than one row somebody has to interpret.
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            $table->foreignId('website_domain_id')->nullable()->constrained('website_domains')->nullOnDelete();
            $table->foreignId('website_hosting_id')->nullable()->constrained('website_hostings')->nullOnDelete();

            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->restrictOnDelete();

            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->text('reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['website_id', 'status']);
            $table->index(['business_account_id', 'status']);
        });

        $serviceStatuses = self::quoted(self::SERVICE_STATUSES);
        $chargeTypes = self::quoted(self::CHARGE_TYPES);
        $chargeStatuses = self::quoted(self::CHARGE_STATUSES);

        DB::unprepared(<<<SQL
            ALTER TABLE website_domains
                ADD CONSTRAINT website_domains_status_known CHECK (status IN ({$serviceStatuses})),
                ADD CONSTRAINT website_domains_fee_not_negative CHECK (fee_minor >= 0),
                ADD CONSTRAINT website_domains_is_lowercase CHECK (domain = lower(domain)),
                ADD CONSTRAINT website_domains_active_has_a_term CHECK (
                    status <> 'active' OR (registered_at IS NOT NULL AND expires_at IS NOT NULL)
                );

            ALTER TABLE website_hostings
                ADD CONSTRAINT website_hostings_status_known CHECK (status IN ({$serviceStatuses})),
                ADD CONSTRAINT website_hostings_fee_not_negative CHECK (fee_minor >= 0),
                ADD CONSTRAINT website_hostings_active_has_a_term CHECK (
                    status <> 'active' OR (started_at IS NOT NULL AND expires_at IS NOT NULL)
                );

            ALTER TABLE website_charges
                ADD CONSTRAINT website_charges_type_known CHECK (type IN ({$chargeTypes})),
                ADD CONSTRAINT website_charges_status_known CHECK (status IN ({$chargeStatuses})),
                ADD CONSTRAINT website_charges_amount_not_negative CHECK (amount_minor >= 0),
                ADD CONSTRAINT website_charges_paid_has_a_transaction CHECK (
                    status <> 'paid' OR (wallet_transaction_id IS NOT NULL AND paid_at IS NOT NULL)
                ),
                ADD CONSTRAINT website_charges_period_is_ordered CHECK (
                    period_start IS NULL OR period_end IS NULL OR period_end > period_start
                );

            -- One live registration per domain name. A cancelled one may be
            -- registered again by somebody else, which a plain unique index
            -- would forbid forever.
            CREATE UNIQUE INDEX website_domains_live_name_unique
                ON website_domains (domain) WHERE status IN ('pending', 'active');

            CREATE TRIGGER website_charges_locked_columns
                BEFORE UPDATE OF public_id, website_id, business_account_id, type, amount_minor, currency_code ON website_charges
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'website_id', 'business_account_id', 'type', 'amount_minor', 'currency_code'
                );
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('website_charges');
        Schema::dropIfExists('website_hostings');
        Schema::dropIfExists('website_domains');
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
