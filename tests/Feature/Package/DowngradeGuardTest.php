<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Package\Actions\OverrideDowngradeLimit;
use App\Domain\Package\DowngradeGuard;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Models\Package;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/*
 * The downgrade guard (P1-74, D16).
 *
 * D16 is emphatic about what must not happen: nothing is auto-removed, and the
 * user is told what to unpublish rather than refused. These hold both halves —
 * the figures a person is owed, and the override that needs a permission and a
 * reason rather than a click.
 */

/**
 * A package with the given limits set.
 *
 * @param  array<string, int|null>  $limits  keyed by PackageFeature value; null is unlimited
 */
function downgradeTestPackage(array $limits): Package
{
    $package = Package::create([
        'slug' => 'small-'.Str::lower(Str::random(8)),
        'name' => 'Starter',
        'fee_minor' => 100000,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

    foreach ($limits as $feature => $value) {
        $package->features()->create([
            'feature' => $feature,
            'value' => $value === null ? null : (string) $value,
        ]);
    }

    return $package->load('features');
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('assessing', function () {
    it('allows a downgrade the account already fits', function () {
        $package = downgradeTestPackage([PackageFeature::ProductPublishLimit->value => 50]);

        $assessment = app(DowngradeGuard::class)->assess($package, [
            PackageFeature::ProductPublishLimit->value => 20,
        ]);

        expect($assessment->isAllowed)->toBeTrue()
            ->and($assessment->excess)->toBeEmpty();
    });

    it('allows a downgrade that lands exactly on the limit', function () {
        // 50 into 50 fits. Refusing here would make the advertised limit a lie.
        $package = downgradeTestPackage([PackageFeature::ProductPublishLimit->value => 50]);

        expect(app(DowngradeGuard::class)->assess($package, [
            PackageFeature::ProductPublishLimit->value => 50,
        ])->isAllowed)->toBeTrue();
    });

    it('blocks it and says exactly how many must go', function () {
        // "You cannot downgrade" gives somebody no way to act.
        $package = downgradeTestPackage([PackageFeature::ProductPublishLimit->value => 50]);

        $assessment = app(DowngradeGuard::class)->assess($package, [
            PackageFeature::ProductPublishLimit->value => 63,
        ]);

        expect($assessment->isAllowed)->toBeFalse()
            ->and($assessment->excess)->toHaveCount(1)
            ->and($assessment->excess[0]->current)->toBe(63)
            ->and($assessment->excess[0]->newLimit)->toBe(50)
            ->and($assessment->excess[0]->mustRemove())->toBe(13);
    });

    it('reports every exceeded limit at once', function () {
        // Telling a user about one, then refusing them again on the next, is
        // how a downgrade takes four attempts.
        $package = downgradeTestPackage([
            PackageFeature::ProductPublishLimit->value => 50,
            PackageFeature::StaffLimit->value => 2,
            PackageFeature::WebsiteLimit->value => 1,
        ]);

        $assessment = app(DowngradeGuard::class)->assess($package, [
            PackageFeature::ProductPublishLimit->value => 63,
            PackageFeature::StaffLimit->value => 5,
            PackageFeature::WebsiteLimit->value => 1,
        ]);

        expect($assessment->excess)->toHaveCount(2)
            ->and($assessment->totalToRemove())->toBe(16)
            ->and($assessment->blocks(PackageFeature::StaffLimit))->toBeTrue()
            ->and($assessment->blocks(PackageFeature::WebsiteLimit))->toBeFalse();
    });

    it('treats an unlimited feature as nothing to exceed', function () {
        $package = downgradeTestPackage([PackageFeature::ProductPublishLimit->value => null]);

        expect(app(DowngradeGuard::class)->assess($package, [
            PackageFeature::ProductPublishLimit->value => 5000,
        ])->isAllowed)->toBeTrue();
    });

    it('does not guard a quota that resets with the term', function () {
        // Exceeding last month's SMS quota is no reason to refuse a package
        // change today.
        $package = downgradeTestPackage([PackageFeature::SmsQuota->value => 100]);

        expect(app(DowngradeGuard::class)->assess($package, [
            PackageFeature::SmsQuota->value => 900,
        ])->isAllowed)->toBeTrue()
            ->and(DowngradeGuard::guardedFeatures())->not->toContain(PackageFeature::SmsQuota);
    });

    it('counts a feature the caller did not mention as zero', function () {
        $package = downgradeTestPackage([PackageFeature::ProductPublishLimit->value => 0]);

        expect(app(DowngradeGuard::class)->assess($package, [])->isAllowed)->toBeTrue();
    });

    it('blocks a package that grants none of something the account has', function () {
        // A limit of zero is a real answer, distinct from unlimited (§8.1).
        $package = downgradeTestPackage([PackageFeature::StaffLimit->value => 0]);

        $assessment = app(DowngradeGuard::class)->assess($package, [
            PackageFeature::StaffLimit->value => 3,
        ]);

        expect($assessment->isAllowed)->toBeFalse()
            ->and($assessment->totalToRemove())->toBe(3);
    });

    it('serialises the figures a screen has to show', function () {
        $package = downgradeTestPackage([PackageFeature::ProductPublishLimit->value => 50]);

        $array = app(DowngradeGuard::class)
            ->assess($package, [PackageFeature::ProductPublishLimit->value => 63])
            ->toArray();

        expect($array['is_allowed'])->toBeFalse()
            ->and($array['total_to_remove'])->toBe(13)
            ->and($array['excess'][0])->toMatchArray([
                'feature' => 'product_publish_limit',
                'current' => 63,
                'new_limit' => 50,
                'must_remove' => 13,
            ]);
    });
});

describe('overriding', function () {
    it('records who, why, and exactly what was waved through', function () {
        // "Override granted" tells a reviewer nothing.
        $account = testBusinessAccount();
        $package = downgradeTestPackage([PackageFeature::ProductPublishLimit->value => 50]);
        $assessment = app(DowngradeGuard::class)->assess($package, [
            PackageFeature::ProductPublishLimit->value => 63,
        ]);

        $admin = testPlatformStaff(PlatformRole::SuperAdmin);

        app(OverrideDowngradeLimit::class)->handle(
            $account,
            $package,
            $assessment,
            $admin,
            'Negotiated plan change, ticket 4821.',
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'package.downgrade_limit_overridden',
            'reason' => 'Negotiated plan change, ticket 4821.',
            'is_sensitive' => true,
        ]);
    });

    it('refuses somebody without the permission', function () {
        $account = testBusinessAccount();
        $package = downgradeTestPackage([PackageFeature::ProductPublishLimit->value => 50]);
        $assessment = app(DowngradeGuard::class)->assess($package, [
            PackageFeature::ProductPublishLimit->value => 63,
        ]);

        expect(fn () => app(OverrideDowngradeLimit::class)->handle(
            $account,
            $package,
            $assessment,
            User::factory()->staff()->create(),
            'Because I said so.',
        ))->toThrow(AuthorizationException::class);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'package.downgrade_limit_overridden',
        ]);
    });

    it('refuses an override with no recorded reason', function () {
        // The reason is what distinguishes a negotiated exception from somebody
        // clicking past a warning.
        $account = testBusinessAccount();
        $package = downgradeTestPackage([PackageFeature::ProductPublishLimit->value => 50]);
        $assessment = app(DowngradeGuard::class)->assess($package, [
            PackageFeature::ProductPublishLimit->value => 63,
        ]);

        expect(fn () => app(OverrideDowngradeLimit::class)->handle(
            $account,
            $package,
            $assessment,
            testPlatformStaff(PlatformRole::SuperAdmin),
            '  ',
        ))->toThrow(InvalidArgumentException::class, 'recorded reason');
    });

    it('refuses to record an override of a downgrade that was never blocked', function () {
        // Noise in a record a reviewer has to read.
        $account = testBusinessAccount();
        $package = downgradeTestPackage([PackageFeature::ProductPublishLimit->value => 50]);
        $assessment = app(DowngradeGuard::class)->assess($package, [
            PackageFeature::ProductPublishLimit->value => 10,
        ]);

        expect(fn () => app(OverrideDowngradeLimit::class)->handle(
            $account,
            $package,
            $assessment,
            testPlatformStaff(PlatformRole::SuperAdmin),
            'Just in case.',
        ))->toThrow(InvalidArgumentException::class, 'not blocked');
    });

    it('removes nothing', function () {
        /*
         * D16's hard rule. An override that deleted the excess to make the
         * numbers agree would be the auto-removal the decision forbids — the
         * account keeps what it has and exceeds the new limit, and publishing
         * more is what the limit then refuses.
         */
        $account = testBusinessAccount();
        $package = downgradeTestPackage([PackageFeature::StaffLimit->value => 0]);
        $staffBefore = $account->memberships()->count();

        app(OverrideDowngradeLimit::class)->handle(
            $account,
            $package,
            app(DowngradeGuard::class)->assess($package, [PackageFeature::StaffLimit->value => 3]),
            testPlatformStaff(PlatformRole::SuperAdmin),
            'Migrating them next week.',
        );

        expect($account->memberships()->count())->toBe($staffBefore);
    });
});
