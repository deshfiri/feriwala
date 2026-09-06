<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Actions\CalculateActivationQuote;
use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Package\Actions\ManagePackages;
use App\Domain\Package\Actions\SelectPackage;
use App\Domain\Package\Data\SubscriptionTerms;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/*
 * A subscription carries its own terms (§8.1, §8.3, §36.2).
 *
 * Editing a package must never change the meaning of a subscription somebody
 * already holds. §8.3 makes a change of terms an upgrade or a renewal —
 * something an account agrees to — not something that happens to them between
 * one request and the next.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
});

/**
 * @param  array<string, string>  $features
 */
function termsTestPackage(array $features = [], int $fee = 500000): Package
{
    return app(ManagePackages::class)->create(
        [
            'name' => 'Growth',
            'slug' => 'growth-'.Str::lower(Str::random(6)),
            'fee_minor' => $fee,
            'registration_fee_minor' => 100000,
            'renewal_fee_minor' => $fee,
            'renewal_frequency' => 'yearly',
            'validity_days' => 365,
            'grace_period_days' => 14,
            'required_deposit_minor' => 200000,
            'minimum_balance_minor' => 50000,
            'currency_code' => 'BDT',
            'is_active' => true,
            'is_public' => true,
        ],
        $features,
        [['charge_type' => 'website_setup', 'amount_minor' => 300000, 'frequency' => 'once']],
        test()->admin,
    );
}

describe('capturing the terms', function () {
    it('records everything the subscription was sold', function () {
        $package = termsTestPackage([PackageFeature::StaffLimit->value => '5']);
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        $subscription = app(SelectPackage::class)->handle($account, $package);
        $terms = $subscription->terms();

        expect($subscription->hasCapturedTerms())->toBeTrue()
            ->and($terms->name)->toBe('Growth')
            ->and($terms->feeMinor)->toBe(500000)
            ->and($terms->registrationFeeMinor)->toBe(100000)
            ->and($terms->renewalFeeMinor)->toBe(500000)
            ->and($terms->renewalFrequency)->toBe('yearly')
            ->and($terms->validityDays)->toBe(365)
            ->and($terms->gracePeriodDays)->toBe(14)
            ->and($terms->requiredDepositMinor)->toBe(200000)
            ->and($terms->minimumBalanceMinor)->toBe(50000)
            ->and($terms->currencyCode)->toBe('BDT')
            ->and($terms->charges)->toHaveCount(1)
            ->and($terms->feature(PackageFeature::StaffLimit))->toBe(5);
    });

    it('keeps the package id, not the slug, as the link back', function () {
        // A slug is a routing decision and may be edited; the terms must still
        // say which package this was.
        $package = termsTestPackage();
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        $subscription = app(SelectPackage::class)->handle($account, $package);

        expect($subscription->terms()->packagePublicId)->toBe($package->public_id);
    });
});

describe('an edit after the sale', function () {
    it('does not change what an existing subscription grants', function () {
        /*
         * The failure this guards: an administrator lowering a staff limit and
         * silently taking a seat away from every account that already bought
         * the larger one.
         */
        $package = termsTestPackage([PackageFeature::StaffLimit->value => '5']);
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        $subscription = app(SelectPackage::class)->handle($account, $package);
        $subscription->forceFill(['status' => UserPackageStatus::Active])->save();
        $account->forceFill(['current_user_package_id' => $subscription->id])->save();

        app(ManagePackages::class)->update(
            $package,
            [],
            [PackageFeature::StaffLimit->value => '1'],
            [],
            $this->admin,
        );

        expect(app(Entitlements::class)->limit($account->refresh(), PackageFeature::StaffLimit))
            ->toBe(5);
    });

    it('does not withdraw a facility an account already bought', function () {
        $package = termsTestPackage([PackageFeature::ApiAccess->value => '1']);
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        $subscription = app(SelectPackage::class)->handle($account, $package);
        $subscription->forceFill(['status' => UserPackageStatus::Active])->save();
        $account->forceFill(['current_user_package_id' => $subscription->id])->save();

        // Withdrawn from the package entirely.
        app(ManagePackages::class)->update($package, [], [], [], $this->admin);

        expect(app(Entitlements::class)->allows($account->refresh(), PackageFeature::ApiAccess))
            ->toBeTrue();
    });

    it('applies the change to the next purchase', function () {
        // The other half: a change of terms is not ignored, it is future.
        $package = termsTestPackage([PackageFeature::StaffLimit->value => '5']);

        app(ManagePackages::class)->update(
            $package,
            [],
            [PackageFeature::StaffLimit->value => '1'],
            [],
            $this->admin,
        );

        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);
        $subscription = app(SelectPackage::class)->handle($account, $package->refresh());

        expect($subscription->terms()->feature(PackageFeature::StaffLimit))->toBe(1);
    });

    it('does not change the price a subscription was quoted', function () {
        $package = termsTestPackage(fee: 500000);
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        $subscription = app(SelectPackage::class)->handle($account, $package);

        app(ManagePackages::class)->update($package, ['fee_minor' => 900000], [], [], $this->admin);

        expect($subscription->refresh()->paid_fee_minor->minorUnits)->toBe(500000)
            ->and($subscription->terms()->feeMinor)->toBe(500000);
    });

    it('leaves a settled payment calculating nothing again', function () {
        // §36.2: an invoice reads its own allocations, never the package row.
        $package = termsTestPackage(fee: 500000);
        $account = testBusinessAccount(AccountStatus::PaymentVerificationPending);

        $quote = app(CalculateActivationQuote::class)->handle($package, account: $account);
        $payment = app(RecordPaymentFromQuote::class)->handle($account, $quote, PaymentPurpose::Activation);

        $before = $payment->allocatedTo(AllocationType::PackageFee)->minorUnits;

        app(ManagePackages::class)->update($package, ['fee_minor' => 900000], [], [], $this->admin);

        expect($payment->fresh()->allocatedTo(AllocationType::PackageFee)->minorUnits)
            ->toBe($before)
            ->toBe(500000);
    });

    it('survives the package being archived entirely', function () {
        $package = termsTestPackage([PackageFeature::StaffLimit->value => '5']);
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        $subscription = app(SelectPackage::class)->handle($account, $package);

        // Expired, so the archive guard lets it through — the subscription row
        // and its terms remain regardless.
        $subscription->forceFill(['status' => UserPackageStatus::Expired])->save();
        app(ManagePackages::class)->archive($package, $this->admin);

        expect($subscription->refresh()->terms()->feature(PackageFeature::StaffLimit))
            ->toBe(5)
            ->and($subscription->terms()->name)->toBe('Growth');
    });
});

describe('subscriptions from before snapshots existed', function () {
    it('falls back to the package rather than granting nothing', function () {
        /*
         * Granting nothing would strand real accounts mid-term. Reading a row
         * that may since have moved is the lesser wrong, and only these rows
         * are exposed to it.
         */
        $package = termsTestPackage([PackageFeature::StaffLimit->value => '5']);
        $account = testBusinessAccount(AccountStatus::Active);

        $legacy = UserPackage::create([
            'business_account_id' => $account->id,
            'package_id' => $package->id,
            'status' => UserPackageStatus::Active,
            'source' => 'purchase',
            'paid_fee_minor' => 500000,
            'currency_code' => 'BDT',
            'started_at' => now()->subMonth(),
            'expires_at' => now()->addYear(),
        ]);

        $account->forceFill(['current_user_package_id' => $legacy->id])->save();

        expect($legacy->hasCapturedTerms())->toBeFalse()
            ->and(app(Entitlements::class)->limit($account->refresh(), PackageFeature::StaffLimit))
            ->toBe(5);
    });
});

describe('the snapshot itself', function () {
    it('records the resolved value, not the silence', function () {
        // A package saying nothing about a feature has a default (§8.1). The
        // subscription records what it was granted, so a later change of
        // default cannot reinterpret an old silence.
        $package = termsTestPackage();

        $terms = SubscriptionTerms::capture($package->load(['features', 'charges']));

        expect($terms->feature(PackageFeature::ApiAccess))->toBe(false)
            ->and($terms->feature(PackageFeature::WholesaleEnabled))->toBe(true);
    });

    it('answers a feature added after the sale with its default', function () {
        // Nobody sold it to this account, which is the honest answer.
        $terms = SubscriptionTerms::fromArray(['features' => []]);

        expect($terms->feature(PackageFeature::ApiAccess))->toBe(false)
            ->and($terms->feature(PackageFeature::StaffLimit))->toBe(0);
    });

    it('round-trips through storage unchanged', function () {
        $package = termsTestPackage([PackageFeature::StaffLimit->value => '5']);

        $captured = SubscriptionTerms::capture($package->load(['features', 'charges']));
        $restored = SubscriptionTerms::fromArray($captured->toArray());

        expect($restored->toArray())->toBe($captured->toArray());
    });
});
