<?php

namespace Database\Seeders;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Actions\CaptureRoundRequirements;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * A working development environment: sign in and see the screens (§43).
 *
 * Deliberately **not** part of `DatabaseSeeder`. This creates logins with a
 * known password, which is exactly what must never reach production — running
 * it there is refused rather than trusted to a habit.
 *
 * The roles here are chosen to make the §7.2 separation visible: a reviewer who
 * decides applications, and somebody who configures the catalogue, are
 * different people with different permissions, and the navigation differs for
 * each. Seeding only a Super Admin would hide that.
 */
class DemoSeeder extends Seeder
{
    /** The password every seeded login shares. Development only. */
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'DemoSeeder creates logins with a known password and must never run in production.'
            );
        }

        $this->call(RolesAndPermissionsSeeder::class);

        $this->configureDeadlines();
        $this->packages();
        $this->documentTypes();

        $this->platformStaff();
        $this->businessAccounts();

        $this->command->info('Seeded. Every login uses the password: '.self::PASSWORD);
    }

    /**
     * A 30-day KYC window, so the deadline and countdown are visible.
     *
     * Off by default in the application itself (§7.4) — inventing a window
     * would restrict real accounts on a number nobody agreed. A development
     * environment is where seeing it matters.
     */
    protected function configureDeadlines(): void
    {
        $settings = app(SettingsRepository::class);

        $settings->define('kyc.deadline_days', 'kyc', SettingType::Integer, 30);
        $settings->define('kyc.deadline_warning_days', 'kyc', SettingType::Integer, 7);
    }

    /**
     * Three plans, so the catalogue and the package-scoped KYC rule both have
     * something real to point at (§8.1).
     */
    protected function packages(): void
    {
        $this->package('starter', 'Starter', 150000, [
            PackageFeature::StaffLimit->value => '0',
            PackageFeature::ProductPublishLimit->value => '25',
            PackageFeature::DedicatedWebsite->value => '0',
        ]);

        $this->package('growth', 'Growth', 500000, [
            PackageFeature::StaffLimit->value => '5',
            PackageFeature::ProductPublishLimit->value => '250',
            PackageFeature::DedicatedWebsite->value => '1',
            PackageFeature::CourierEnabled->value => '1',
        ]);

        // No staff limit row at all: unlimited is the absence of a cap, and
        // seeding it proves the distinction survives a round trip (§8.1).
        $this->package('enterprise', 'Enterprise', 1500000, [
            PackageFeature::ProductPublishLimit->value => '5000',
            PackageFeature::DedicatedWebsite->value => '1',
            PackageFeature::CourierEnabled->value => '1',
            PackageFeature::ApiAccess->value => '1',
            PackageFeature::FulfillmentEnabled->value => '1',
        ]);
    }

    /**
     * @param  array<string, string>  $features
     */
    protected function package(string $slug, string $name, int $feeMinor, array $features): Package
    {
        /** @var Package $package */
        $package = Package::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'short_description' => $name.' plan',
                'fee_minor' => $feeMinor,
                'currency_code' => 'BDT',
                'validity_days' => 365,
                'renewal_fee_minor' => $feeMinor,
                'renewal_frequency' => 'yearly',
                'grace_period_days' => 14,
                'required_deposit_minor' => 0,
                'minimum_balance_minor' => 0,
                'is_active' => true,
                'is_public' => true,
            ],
        );

        $package->features()->delete();

        foreach ($features as $feature => $value) {
            $package->features()->create(['feature' => $feature, 'value' => $value]);
        }

        return $package->refresh();
    }

    /**
     * A catalogue that exercises every scoping shape (§7.2).
     */
    protected function documentTypes(): void
    {
        $national = $this->documentType([
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
        $this->documentType([
            'key' => 'trade_licence',
            'name' => 'Trade licence',
            'instructions' => 'Current year, issued by your city corporation.',
            'is_required' => false,
            'sort_order' => 2,
        ])->scopes()->create([
            'country_code' => 'BD',
            'is_required' => true,
        ]);

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

        // Package-scoped: only Enterprise accounts are asked for this.
        $this->documentType([
            'key' => 'company_registration',
            'name' => 'Company registration certificate',
            'instructions' => 'The certificate of incorporation, all pages.',
            'is_required' => false,
            'sort_order' => 4,
        ])->scopes()->create([
            'package_slug' => 'enterprise',
            'is_required' => true,
        ]);

        // Paused: configured, but not currently on the form.
        $this->documentType([
            'key' => 'bank_statement',
            'name' => 'Bank statement',
            'instructions' => 'Six months, stamped by the branch.',
            'is_required' => false,
            'is_active' => false,
            'sort_order' => 5,
        ]);

        unset($national);
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
     * Feriwala's own staff — no business account between them (D23).
     */
    protected function platformStaff(): void
    {
        $this->staff('admin@feriwala.test', 'Platform Administrator')
            ->assignRole(PlatformRole::SuperAdmin->value);

        // Reviews applications **and** configures the catalogue.
        $this->staff('kyc@feriwala.test', 'Nusrat KYC Manager')
            ->assignRole(PlatformRole::KycManager->value);

        /*
         * Deliberately narrower than the KYC Manager: this login can see the
         * queue but not configure requirements, which is what makes the §7.2
         * split visible in the navigation.
         */
        $this->staff('reviewer@feriwala.test', 'Rakib Reviewer')
            ->givePermissionTo([
                PermissionCatalogue::name(PermissionModule::Kyc, PermissionAction::View),
                PermissionCatalogue::name(PermissionModule::Kyc, PermissionAction::Approve),
                PermissionCatalogue::name(PermissionModule::Kyc, PermissionAction::ViewKycDocuments),
            ]);
    }

    protected function staff(string $email, string $name): User
    {
        /** @var User $user */
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
                'mobile' => $this->mobileFor($email),
                'mobile_verified_at' => now(),
                'country' => 'BD',
            ],
        );

        return $user;
    }

    /**
     * Three accounts at three points in the funnel, so every screen has
     * something to show.
     */
    protected function businessAccounts(): void
    {
        // Trading, with an approved round behind it — the account an
        // administrator can request a KYC update from (§7.2).
        $active = $this->account(
            'owner@feriwala.test',
            'Karim Traders',
            AccountStatus::Active,
        );

        $this->round($active, KycStatus::Approved, submittedDaysAgo: 40, reviewedDaysAgo: 38);

        // Mid-onboarding: an open draft, so the KYC form and the history screen
        // both have a live round.
        $onboarding = $this->account(
            'applicant@feriwala.test',
            'Shanto Enterprise',
            AccountStatus::KycPending,
        );

        $this->round($onboarding, KycStatus::Draft);

        // Sent back for corrections, so the feedback path is visible.
        $corrections = $this->account(
            'resubmit@feriwala.test',
            'Mitu Fashion House',
            AccountStatus::KycResubmissionRequired,
        );

        $round = $this->round($corrections, KycStatus::ResubmissionRequired, submittedDaysAgo: 5, reviewedDaysAgo: 3);

        $round->reviews()->create([
            'reviewer_id' => User::query()->where('email', 'kyc@feriwala.test')->value('id'),
            'from_status' => KycStatus::UnderReview,
            'to_status' => KycStatus::ResubmissionRequired,
            'reason' => 'Identity document unreadable.',
            'internal_note' => 'Third attempt from this applicant — watch for a pattern.',
            'user_visible_feedback' => 'Your ID photo is too dark to read. Please send a clearer picture in daylight.',
        ]);
    }

    protected function account(string $email, string $name, AccountStatus $status): BusinessAccount
    {
        $owner = $this->staff($email, $name.' Owner');

        /** @var BusinessAccount $account */
        $account = BusinessAccount::query()->updateOrCreate(
            ['owner_id' => $owner->id],
            [
                'name' => $name,
                'slug' => str($name)->slug()->toString(),
                'status' => $status,
                'activated_at' => $status->isActivated() ? now()->subMonths(6) : null,
            ],
        );

        $account->memberships()->firstOrCreate(
            ['user_id' => $owner->id],
            ['role' => AccountRole::Owner],
        );

        return $account->refresh();
    }

    protected function round(
        BusinessAccount $account,
        KycStatus $status,
        ?int $submittedDaysAgo = null,
        ?int $reviewedDaysAgo = null,
    ): KycSubmission {
        /** @var KycSubmission $submission */
        $submission = KycSubmission::query()->updateOrCreate(
            ['business_account_id' => $account->id, 'round' => 1],
            [
                'status' => $status,
                'submitted_at' => $submittedDaysAgo === null ? null : now()->subDays($submittedDaysAgo),
                'reviewed_at' => $reviewedDaysAgo === null ? null : now()->subDays($reviewedDaysAgo),
                'deadline_at' => now()->addDays(12),
            ],
        );

        // Through the real action, so the seeded round carries the same
        // snapshot a real one would (§7.2).
        app(CaptureRoundRequirements::class)->handle($submission);

        return $submission;
    }

    protected function mobileFor(string $email): string
    {
        // Stable per address, so re-seeding does not collide on the unique
        // index or hand the same number to two logins.
        return '+88017'.substr((string) abs(crc32($email)), 0, 8);
    }
}
