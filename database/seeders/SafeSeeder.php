<?php

namespace Database\Seeders;

use App\Domain\Bank\Actions\ImportBdBanks;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Location\Actions\ImportBdLocations;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Support\Security\SessionPolicy;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Reference data safe to run against a populated database, including
 * production.
 *
 * Nothing here creates a `User`, a `Payment`, or a `Package` — only the
 * platform's own permission catalogue, KYC document-type catalogue, two
 * settings, and the bundled Bangladesh location directory. Every step is
 * idempotent (`updateOrCreate`, or an importer that upserts by natural key
 * and only ever deactivates a row it no longer recognises — see
 * `ImportBdLocations`), so running this again changes nothing that already
 * matches and never deletes or duplicates what came before.
 *
 * `php artisan db:seed --class=SafeSeeder`
 */
class SafeSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $this->settings();
        $this->documentTypes();
        $this->locations();
        $this->banks();

        $this->command->info('Safe seed complete.');
    }

    /**
     * Off by default in the application itself (§7.4) — inventing a KYC
     * deadline would restrict real accounts on a number nobody agreed. Only
     * the setting *definitions* are seeded; their values stay whatever an
     * administrator has already chosen, or the framework default.
     */
    protected function settings(): void
    {
        $settings = app(SettingsRepository::class);

        $settings->define('kyc.deadline_days', 'kyc', SettingType::Integer, 30);
        $settings->define('kyc.deadline_warning_days', 'kyc', SettingType::Integer, 7);

        $settings->define(
            SessionPolicy::LIFETIME,
            'security',
            SettingType::Integer,
            label: 'Session lifetime (minutes)',
            description: 'How long a signed-in session survives without activity. Leave empty to use the deployed SESSION_LIFETIME.',
        );
    }

    /**
     * A catalogue that exercises every scoping shape (§7.2) — except the
     * package scope is skipped, not broken, when no Enterprise package
     * exists to point at (this seeder never creates one).
     */
    protected function documentTypes(): void
    {
        $this->documentType([
            'key' => 'national_id',
            'name' => 'National ID',
            'instructions' => 'Both sides, in colour, with all four corners visible.',
            'is_required' => true,
            'sort_order' => 0,
        ]);

        $this->documentType([
            'key' => 'proof_of_address',
            'name' => 'Proof of address',
            'instructions' => 'A utility bill or bank statement from the last three months.',
            'is_required' => true,
            'sort_order' => 1,
        ]);

        // Country-scoped: a trade licence only Bangladeshi accounts are asked
        // for, and mandatory there.
        $tradeLicence = $this->documentType([
            'key' => 'trade_licence',
            'name' => 'Trade licence',
            'instructions' => 'Current year, issued by your city corporation.',
            'is_required' => false,
            'sort_order' => 2,
        ]);

        $tradeLicence->scopes()->updateOrCreate(
            ['country_code' => 'BD', 'package_public_id' => null],
            ['is_required' => true],
        );

        // A typed value rather than a file.
        $this->documentType([
            'key' => 'tin',
            'name' => 'Tax identification number',
            'instructions' => 'The TIN on your certificate, digits only.',
            'is_required' => false,
            'requires_file' => false,
            'requires_value' => true,
            'value_label' => 'TIN',
            'sort_order' => 3,
        ]);

        // Package-scoped: only Enterprise accounts are asked for this — but
        // this seeder creates no packages, so the scope is added only when
        // one already exists to scope to (production, or after DemoSeeder's
        // own packages() step has run first).
        $companyRegistration = $this->documentType([
            'key' => 'company_registration',
            'name' => 'Company registration certificate',
            'instructions' => 'The certificate of incorporation, all pages.',
            'is_required' => false,
            'sort_order' => 4,
        ]);

        $enterprisePackageId = Package::query()->where('slug', 'enterprise')->value('public_id');

        if ($enterprisePackageId !== null) {
            $companyRegistration->scopes()->updateOrCreate(
                ['package_public_id' => $enterprisePackageId, 'country_code' => null],
                ['is_required' => true],
            );
        }

        // Paused: configured, but not currently on the form.
        $this->documentType([
            'key' => 'bank_statement',
            'name' => 'Bank statement',
            'instructions' => 'Six months, stamped by the branch.',
            'is_required' => false,
            'is_active' => false,
            'sort_order' => 5,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function documentType(array $attributes): KycDocumentType
    {
        return KycDocumentType::query()->updateOrCreate(
            ['key' => $attributes['key']],
            [
                'is_active' => true,
                'requires_file' => true,
                'requires_value' => false,
                'accepted_mime_types' => ['image/jpeg', 'image/png', 'application/pdf'],
                'max_size_kb' => 5120,
                ...$attributes,
            ],
        );
    }

    /**
     * The bundled Bangladesh location directory (Location Directory + Shared
     * Address module) — the same importer `bd-locations:import` runs,
     * validated, then upserted by natural key.
     */
    protected function locations(): void
    {
        $report = app(ImportBdLocations::class)->handle('import');

        if (! $report->isValid()) {
            throw new RuntimeException('Bangladesh location import failed validation: '.implode('; ', $report->problems));
        }

        $this->command->info(sprintf(
            'Locations: divisions=%d districts=%d upazilas=%d unions=%d deactivated=%d',
            $report->counts['division'],
            $report->counts['district'],
            $report->counts['upazila'],
            $report->counts['union'],
            $report->counts['deactivated'],
        ));
    }

    /**
     * The bundled Bangladesh bank/branch directory (Bank Directory batch),
     * linked to the locations directory above by district -- must run after
     * {@see locations()} for that resolution to find anything.
     */
    protected function banks(): void
    {
        $report = app(ImportBdBanks::class)->handle('import');

        if (! $report->isValid()) {
            throw new RuntimeException('Bangladesh bank import failed validation: '.implode('; ', $report->problems));
        }

        $this->command->info(sprintf(
            'Banks: banks=%d branches=%d deactivated_banks=%d deactivated_branches=%d unmatched_districts=%d',
            $report->counts['banks'],
            $report->counts['branches'],
            $report->counts['deactivated_banks'],
            $report->counts['deactivated_branches'],
            count($report->unmatchedDistricts),
        ));
    }
}
