<?php

use App\Domain\Website\Enums\WebsiteConnectionHealth;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteTheme;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated partner websites: every §16.2 setup field (P5-8).
 *
 * One row per storefront. The website belongs to a business account and names
 * the subscription that entitles it, because §16.1 makes the features a
 * question of the package — reading the account's package live would change
 * what a website is entitled to between one request and the next.
 *
 * The charges are stored **as they were agreed**, not looked up each time a
 * screen renders: a fee rule that changes next month must not rewrite what a
 * partner was told their website would cost (§9).
 *
 * The address is split in two on purpose. `subdomain` is the one Feriwala
 * always provides and can therefore guarantee is unique; `domain` is the
 * partner's own, may be absent for a long time, and is provisioned by hand
 * (D9). Both are lowercase by constraint, so two rows cannot differ by case
 * alone and resolve to the same host.
 */
return new class extends Migration
{
    /** Every §16.4 status, as the column will accept them. */
    private const STATUSES = [
        'setup_pending', 'deposit_pending', 'development', 'api_connection_pending',
        'active', 'low_wallet_balance', 'grace_period', 'temporarily_disabled',
        'package_expired', 'domain_renewal_pending', 'hosting_renewal_pending',
        'suspended', 'maintenance', 'closed',
    ];

    private const THEMES = ['classic', 'modern', 'minimal'];

    private const CONNECTION_HEALTH = ['unknown', 'healthy', 'degraded', 'failing'];

    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();

            // The subscription this website is entitled by (§16.1, §8.3). Kept
            // rather than re-read, so a renewal that writes a new row does not
            // silently move the website onto different terms.
            $table->foreignId('user_package_id')->nullable()->constrained('user_packages')->nullOnDelete();

            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('subdomain', 63)->unique();
            $table->string('domain', 253)->nullable()->unique();

            $table->string('status', 32)->default(WebsiteStatus::SetupPending->value);

            // What it costs, as agreed (§16.2, §9).
            $table->char('currency_code', 3)->default('BDT');
            $table->bigInteger('setup_fee_minor')->default(0);
            $table->bigInteger('domain_fee_minor')->default(0);
            $table->bigInteger('hosting_fee_minor')->default(0);
            $table->bigInteger('required_deposit_minor')->default(0);
            $table->bigInteger('minimum_balance_minor')->default(0);

            // Branding and contact details, managed from the ERP (§16.3, P5-12).
            $table->string('tagline', 160)->nullable();
            $table->text('about')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('banner_path')->nullable();
            $table->string('primary_color', 7)->default('#111111');
            $table->string('secondary_color', 7)->default('#f97316');
            $table->string('theme', 32)->default(WebsiteTheme::Classic->value);
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->text('contact_address')->nullable();

            /*
             * Payment and shipping configuration (§16.2). Payment is a set of
             * switches over Feriwala's own gateways and never a credential: the
             * platform is merchant of record and a partner holds no gateway
             * account of its own (D12).
             */
            $table->jsonb('payment_config')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('shipping_config')->default(DB::raw("'{}'::jsonb"));

            // The integration's own state (§16.2, §17.3, P5-28).
            $table->timestamp('api_connected_at')->nullable();
            $table->timestamp('webhook_connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('connection_health', 16)->default(WebsiteConnectionHealth::Unknown->value);

            // The lifecycle's own dates (§16.4, §24.3).
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->text('maintenance_message')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_account_id', 'status']);
            $table->index('status');
        });

        /*
         * Written out rather than read from the enums, because the constraint
         * has to be a literal the database keeps: a migration that generates its
         * own SQL from application code would change what an old migration
         * created the day somebody edits an enum. The drift between the two is
         * asserted instead — see tests/Feature/Website/WebsiteStatusTest.php.
         */
        $statuses = self::quoted(self::STATUSES);
        $themes = self::quoted(self::THEMES);
        $health = self::quoted(self::CONNECTION_HEALTH);

        DB::unprepared(<<<SQL
            ALTER TABLE websites
                ADD CONSTRAINT websites_status_known CHECK (status IN ({$statuses})),
                ADD CONSTRAINT websites_theme_known CHECK (theme IN ({$themes})),
                ADD CONSTRAINT websites_connection_health_known CHECK (connection_health IN ({$health})),
                ADD CONSTRAINT websites_charges_not_negative CHECK (
                    setup_fee_minor >= 0 AND domain_fee_minor >= 0 AND hosting_fee_minor >= 0
                    AND required_deposit_minor >= 0 AND minimum_balance_minor >= 0
                ),
                ADD CONSTRAINT websites_host_is_lowercase CHECK (
                    subdomain = lower(subdomain) AND (domain IS NULL OR domain = lower(domain))
                ),
                ADD CONSTRAINT websites_colors_are_hex CHECK (
                    primary_color ~ '^#[0-9a-f]{6}\$' AND secondary_color ~ '^#[0-9a-f]{6}\$'
                ),
                ADD CONSTRAINT websites_suspension_has_a_reason CHECK (
                    suspended_at IS NULL OR suspension_reason IS NOT NULL
                );

            CREATE TRIGGER websites_locked_columns
                BEFORE UPDATE OF public_id, business_account_id ON websites
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'business_account_id');
        SQL);

        /*
         * The order column that has been waiting for this table since P6-1.
         * Orders carry no website yet, so the key can be added outright.
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_website_id_foreign FOREIGN KEY (website_id)
                REFERENCES websites (id) ON DELETE RESTRICT;

            CREATE INDEX orders_website_id_index ON orders (website_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_website_id_foreign;
            DROP INDEX IF EXISTS orders_website_id_index;
        SQL);

        Schema::dropIfExists('websites');
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
