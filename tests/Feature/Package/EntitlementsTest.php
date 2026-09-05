<?php

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;

/**
 * @param  array<string, bool|int|string|null>  $features
 */
function packageWith(array $features = [], array $attributes = []): Package
{
    $package = Package::create([
        'name' => 'Growth',
        'fee_minor' => 500000,
        ...$attributes,
    ]);

    foreach ($features as $key => $value) {
        $feature = PackageFeature::from($key);

        $package->features()->create([
            'feature' => $key,
            'value' => $feature->type()->serialise($value),
        ]);
    }

    return $package->fresh(['features']);
}

function subscribe(BusinessAccount $account, Package $package, array $attributes = []): UserPackage
{
    $subscription = UserPackage::create([
        'business_account_id' => $account->id,
        'package_id' => $package->id,
        'status' => UserPackageStatus::Active,
        'started_at' => now()->subDay(),
        ...$attributes,
    ]);

    $account->forceFill(['current_user_package_id' => $subscription->id])->save();

    return $subscription;
}

function entitlements(): Entitlements
{
    return app(Entitlements::class);
}

describe('with no package', function () {
    it('grants no facility', function () {
        $account = testBusinessAccount();

        expect(entitlements()->allows($account, PackageFeature::DedicatedWebsite))->toBeFalse()
            ->and(entitlements()->allows($account, PackageFeature::ApiAccess))->toBeFalse();
    });

    it('reports a limit of zero, never unlimited', function () {
        // The dangerous failure would be reading "no package" as "no limit".
        $account = testBusinessAccount();

        expect(entitlements()->limit($account, PackageFeature::ProductPublishLimit))->toBe(0)
            ->and(entitlements()->hasCapacityFor($account, PackageFeature::ProductPublishLimit, 0))->toBeFalse();
    });
});

describe('feature defaults', function () {
    it('grants nothing a package did not mention', function () {
        // A misconfigured package should under-deliver visibly, not hand out
        // free websites.
        $account = testBusinessAccount();
        subscribe($account, packageWith());

        expect(entitlements()->allows($account, PackageFeature::DedicatedWebsite))->toBeFalse()
            ->and(entitlements()->limit($account, PackageFeature::StaffLimit))->toBe(0);
    });

    it('leaves both business methods on unless withdrawn', function () {
        // §10.3: an active account may use dropshipping, wholesale, or both.
        $account = testBusinessAccount();
        subscribe($account, packageWith());

        expect(entitlements()->allows($account, PackageFeature::WholesaleEnabled))->toBeTrue()
            ->and(entitlements()->allows($account, PackageFeature::DropshippingEnabled))->toBeTrue();
    });

    it('lets a package withdraw a business method', function () {
        $account = testBusinessAccount();
        subscribe($account, packageWith(['wholesale_enabled' => false]));

        expect(entitlements()->allows($account, PackageFeature::WholesaleEnabled))->toBeFalse();
    });
});

describe('limits', function () {
    it('reads the configured cap', function () {
        $account = testBusinessAccount();
        subscribe($account, packageWith(['product_publish_limit' => 50]));

        expect(entitlements()->limit($account, PackageFeature::ProductPublishLimit))->toBe(50);
    });

    it('allows one more below the cap and refuses at it', function () {
        $account = testBusinessAccount();
        subscribe($account, packageWith(['product_publish_limit' => 50]));

        expect(entitlements()->hasCapacityFor($account, PackageFeature::ProductPublishLimit, 49))->toBeTrue()
            ->and(entitlements()->hasCapacityFor($account, PackageFeature::ProductPublishLimit, 50))->toBeFalse();
    });

    it('accounts for adding several at once', function () {
        $account = testBusinessAccount();
        subscribe($account, packageWith(['product_publish_limit' => 50]));

        expect(entitlements()->hasCapacityFor($account, PackageFeature::ProductPublishLimit, 45, adding: 5))->toBeTrue()
            ->and(entitlements()->hasCapacityFor($account, PackageFeature::ProductPublishLimit, 45, adding: 6))->toBeFalse();
    });

    it('treats an explicit null as unlimited', function () {
        // Null means unlimited; zero means none. Overloading one for the other
        // would make "no staff allowed" indistinguishable from "unlimited".
        $account = testBusinessAccount();
        subscribe($account, packageWith(['staff_limit' => null]));

        expect(entitlements()->limit($account, PackageFeature::StaffLimit))->toBeNull()
            ->and(entitlements()->hasCapacityFor($account, PackageFeature::StaffLimit, 9999))->toBeTrue()
            ->and(entitlements()->remaining($account, PackageFeature::StaffLimit, 10))->toBeNull();
    });

    it('distinguishes a zero limit from unlimited', function () {
        $account = testBusinessAccount();
        subscribe($account, packageWith(['staff_limit' => 0]));

        expect(entitlements()->limit($account, PackageFeature::StaffLimit))->toBe(0)
            ->and(entitlements()->hasCapacityFor($account, PackageFeature::StaffLimit, 0))->toBeFalse();
    });

    it('reports how many remain', function () {
        $account = testBusinessAccount();
        subscribe($account, packageWith(['product_publish_limit' => 50]));

        expect(entitlements()->remaining($account, PackageFeature::ProductPublishLimit, 42))->toBe(8)
            ->and(entitlements()->remaining($account, PackageFeature::ProductPublishLimit, 60))->toBe(0);
    });
});

describe('subscription state', function () {
    it('grants nothing while payment is outstanding', function () {
        // §5.1 requires payment before activation — entitlements before payment
        // would let someone trade for free.
        $account = testBusinessAccount();
        subscribe($account, packageWith(['dedicated_website' => true]), [
            'status' => UserPackageStatus::PendingPayment,
        ]);

        expect(entitlements()->allows($account, PackageFeature::DedicatedWebsite))->toBeFalse();
    });

    it('grants nothing once expired', function () {
        $account = testBusinessAccount();
        subscribe($account, packageWith(['dedicated_website' => true]), [
            'status' => UserPackageStatus::Expired,
        ]);

        expect(entitlements()->allows($account, PackageFeature::DedicatedWebsite))->toBeFalse();
    });

    it('keeps granting while a renewal is merely due', function () {
        // Cutting a partner off the moment an invoice is late would strand live
        // customer orders (§8.4).
        $account = testBusinessAccount();
        subscribe($account, packageWith(['dedicated_website' => true]), [
            'status' => UserPackageStatus::RenewalDue,
        ]);

        expect(entitlements()->allows($account, PackageFeature::DedicatedWebsite))->toBeTrue();
    });

    it('keeps granting inside the grace period', function () {
        // §8.4 restores rather than severs.
        $account = testBusinessAccount();
        subscribe($account, packageWith(['dedicated_website' => true]), [
            'status' => UserPackageStatus::GracePeriod,
            'expires_at' => now()->subDay(),
            'grace_ends_at' => now()->addDays(6),
        ]);

        expect(entitlements()->allows($account, PackageFeature::DedicatedWebsite))->toBeTrue();
    });

    it('stops granting once the grace period ends', function () {
        $account = testBusinessAccount();
        subscribe($account, packageWith(['dedicated_website' => true]), [
            'status' => UserPackageStatus::GracePeriod,
            'expires_at' => now()->subDays(10),
            'grace_ends_at' => now()->subDay(),
        ]);

        expect(entitlements()->allows($account, PackageFeature::DedicatedWebsite))->toBeFalse();
    });

    it('grants nothing before the subscription starts', function () {
        $account = testBusinessAccount();
        subscribe($account, packageWith(['dedicated_website' => true]), [
            'started_at' => now()->addWeek(),
        ]);

        expect(entitlements()->allows($account, PackageFeature::DedicatedWebsite))->toBeFalse();
    });
});

describe('package availability', function () {
    it('lists only active packages inside their window', function () {
        packageWith([], ['name' => 'Live', 'slug' => 'live']);
        packageWith([], ['name' => 'Off', 'slug' => 'off', 'is_active' => false]);
        packageWith([], ['name' => 'Future', 'slug' => 'future', 'available_from' => now()->addWeek()]);
        packageWith([], ['name' => 'Past', 'slug' => 'past', 'available_until' => now()->subDay()]);

        expect(Package::available()->pluck('name')->all())->toBe(['Live']);
    });

    it('keeps a private package out of the public list', function () {
        packageWith([], ['name' => 'Public', 'slug' => 'public-one']);
        packageWith([], ['name' => 'Bespoke', 'slug' => 'bespoke', 'is_public' => false]);

        expect(Package::publiclyListed()->pluck('name')->all())->toBe(['Public'])
            ->and(Package::available()->count())->toBe(2);
    });
});

it('stores package money as Money, never a float', function () {
    $package = packageWith([], ['fee_minor' => 500000]);

    expect($package->fee_minor->minorUnits)->toBe(500000)
        ->and($package->fee_minor->format())->toBe('৳5,000.00');
});
