<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\ActivateAccount;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Package\Actions\ManagePackages;
use App\Domain\Package\Actions\SelectPackage;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\SubscriptionSource;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Models\User;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The subscription record (P1-36, §8.2, §8.4).
 *
 * A term is a contract: what was bought, for how long, on what terms, and how
 * it was come by. Two things were wrong — the term's length came from the live
 * package rather than from what was paid for, and the status was assignable to
 * anything.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
});

function recordTestPackage(int $validityDays = 365, int $graceDays = 14): Package
{
    return app(ManagePackages::class)->create(
        [
            'name' => 'Growth',
            'slug' => 'growth-'.Str::lower(Str::random(6)),
            'fee_minor' => 500000,
            'renewal_fee_minor' => 500000,
            'renewal_frequency' => 'yearly',
            'validity_days' => $validityDays,
            'grace_period_days' => $graceDays,
            'required_deposit_minor' => 0,
            'minimum_balance_minor' => 0,
            'currency_code' => 'BDT',
            'is_active' => true,
            'is_public' => true,
        ],
        [PackageFeature::StaffLimit->value => '5'],
        [],
        test()->admin,
    );
}

describe('the source', function () {
    it('is typed, and says the account bought this term', function () {
        $package = recordTestPackage();
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        $subscription = app(SelectPackage::class)->handle($account, $package);

        expect($subscription->source)->toBe(SubscriptionSource::Purchase)
            ->and($subscription->source->isPaid())->toBeTrue();
    });

    it('keeps granted terms out of revenue', function () {
        // A promotional or administratively assigned term is real entitlement
        // and no money; counting it as a sale would overstate earnings.
        expect(SubscriptionSource::Promotional->isPaid())->toBeFalse()
            ->and(SubscriptionSource::Manual->isPaid())->toBeFalse()
            ->and(SubscriptionSource::Renewal->isPaid())->toBeTrue();
    });
});

describe('the status machine', function () {
    it('lets an unpaid choice go live, be replaced, or be abandoned', function () {
        expect(UserPackageStatus::PendingPayment->transitionsTo())->toBe([
            UserPackageStatus::Active,
            UserPackageStatus::Superseded,
            UserPackageStatus::Cancelled,
        ]);
    });

    it('will not revive an expired term', function () {
        /*
         * The failure this guards: a term set back to Active without a payment.
         * A new term is a new subscription with its own source and its own
         * captured terms — not this row brought back to life.
         */
        $package = recordTestPackage();
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        $subscription = app(SelectPackage::class)->handle($account, $package);
        $subscription->forceFill(['status' => UserPackageStatus::Expired])->save();

        expect(fn () => $subscription->transitionTo(UserPackageStatus::Active))
            ->toThrow(IllegalStateTransition::class);
    });

    it('will not revive a superseded choice', function () {
        $package = recordTestPackage();
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        $first = app(SelectPackage::class)->handle($account, $package);
        app(SelectPackage::class)->handle($account, $package);

        expect($first->refresh()->status)->toBe(UserPackageStatus::Superseded)
            ->and(fn () => $first->transitionTo(UserPackageStatus::Active))
            ->toThrow(IllegalStateTransition::class);
    });

    it('restores rather than severs when a late renewal arrives', function () {
        // §8.4: a partner whose invoice is late must not lose live orders.
        expect(UserPackageStatus::RenewalDue->transitionsTo())
            ->toContain(UserPackageStatus::Active)
            ->and(UserPackageStatus::GracePeriod->transitionsTo())
            ->toContain(UserPackageStatus::Active);
    });

    it('treats expiry, cancellation and supersession as the end', function (UserPackageStatus $status) {
        expect($status->isTerminal())->toBeTrue();
    })->with([
        UserPackageStatus::Expired,
        UserPackageStatus::Cancelled,
        UserPackageStatus::Superseded,
    ]);
});

describe('the term', function () {
    it('is measured from what the account bought', function () {
        /*
         * The failure this guards: an administrator shortening validity between
         * purchase and approval, quietly selling a shorter year than the one
         * that was paid for. Approval can be days later — exactly the window in
         * which a price list gets edited.
         */
        $package = recordTestPackage(validityDays: 365, graceDays: 14);
        $account = testAccountReadyForActivation();

        $subscription = app(SelectPackage::class)->handle($account, $package);

        app(ManagePackages::class)->update(
            $package,
            ['validity_days' => 30, 'grace_period_days' => 0],
            [],
            [],
            $this->admin,
        );

        app(ActivateAccount::class)->handle($account->refresh(), $this->admin->id);

        $subscription->refresh();

        expect($subscription->status)->toBe(UserPackageStatus::Active)
            ->and($subscription->started_at->addDays(365)->toDateString())
            ->toBe($subscription->expires_at->toDateString())
            ->and($subscription->grace_ends_at->toDateString())
            ->toBe($subscription->started_at->addDays(379)->toDateString());
    });

    it('does not expire at all when validity is unset', function () {
        // Null is a term that does not end. Distinct from zero, which nothing
        // sets and which would expire on the day it began.
        $package = recordTestPackage(validityDays: 0);
        $package->forceFill(['validity_days' => null])->save();

        $account = testAccountReadyForActivation();
        $subscription = app(SelectPackage::class)->handle($account, $package->refresh());

        app(ActivateAccount::class)->handle($account->refresh(), $this->admin->id);

        expect($subscription->refresh()->expires_at)->toBeNull()
            ->and($subscription->grace_ends_at)->toBeNull();
    });

    it('starts at activation, not at purchase', function () {
        // §5.1: a slow approval must not eat paid days.
        $package = recordTestPackage();
        $account = testAccountReadyForActivation();

        $subscription = app(SelectPackage::class)->handle($account, $package);

        expect($subscription->started_at)->toBeNull();

        $this->travel(3)->days();
        app(ActivateAccount::class)->handle($account->refresh(), $this->admin->id);

        expect($subscription->refresh()->started_at->toDateString())
            ->toBe(now()->toDateString());
    });
});

describe('the account screen', function () {
    it('shows the term an applicant chose before they have paid', function () {
        // "What did I choose and what will it cost" is asked before paying.
        $package = recordTestPackage();
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        app(SelectPackage::class)->handle($account, $package);

        $this->actingAs($account->owner)
            ->get(route('subscription.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/subscription')
                ->where('current.package', 'Growth')
                ->where('current.status', UserPackageStatus::PendingPayment->value)
                ->where('current.entitles', false),
            );
    });

    it('shows the limits this term is actually held to', function () {
        $package = recordTestPackage();
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        app(SelectPackage::class)->handle($account, $package);

        // Changed after the sale. The account must still see what it bought.
        app(ManagePackages::class)->update(
            $package,
            [],
            [PackageFeature::StaffLimit->value => '1'],
            [],
            $this->admin,
        );

        $this->actingAs($account->owner)
            ->get(route('subscription.show'))
            ->assertInertia(function (Assert $page) {
                $features = collect($page->toArray()['props']['current']['features']);

                expect($features->firstWhere('key', 'staff_limit')['value'])->toBe(5);
            });
    });

    it('offers a package to an account that has none', function () {
        $account = testBusinessAccount(AccountStatus::Registered);

        $this->actingAs($account->owner)
            ->get(route('subscription.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current', null)
                ->has('history', 0),
            );
    });

    it('still shows a closed term to an account left with no current one', function () {
        /*
         * An account that cancelled has no current term, and the screen would
         * otherwise say only "you have no package" — leaving the person who
         * cancelled with no record that they ever had one.
         */
        $package = recordTestPackage();
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        $subscription = app(SelectPackage::class)->handle($account, $package);
        $subscription->transitionTo(UserPackageStatus::Cancelled)->save();

        $this->actingAs($account->owner)
            ->get(route('subscription.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('current', null)
                ->has('history', 1)
                ->where('history.0.status', UserPackageStatus::Cancelled->value),
            );
    });

    it('lists previous terms alongside the current one', function () {
        $package = recordTestPackage();
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        app(SelectPackage::class)->handle($account, $package);
        app(SelectPackage::class)->handle($account, $package);

        $this->actingAs($account->owner)
            ->get(route('subscription.show'))
            ->assertInertia(fn (Assert $page) => $page->has('history', 2));
    });

    it('has no account identifier to substitute', function () {
        // §31.3: self-scoped from membership, never from the URL.
        expect(route('subscription.show', absolute: false))
            ->toBe('/settings/subscription');
    });

    it('shows one account nothing of another', function () {
        $package = recordTestPackage();
        $mine = testBusinessAccount(AccountStatus::PackageSelectionPending);
        $theirs = testBusinessAccount(AccountStatus::PackageSelectionPending);

        app(SelectPackage::class)->handle($theirs, $package);

        $this->actingAs($mine->owner)
            ->get(route('subscription.show'))
            ->assertInertia(fn (Assert $page) => $page->where('current', null));
    });

    it('turns away platform staff, who have no business account', function () {
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('subscription.show'))
            ->assertForbidden();
    });

    it('stays reachable before activation', function () {
        // On the §5.4 allow-list: the screen is part of deciding to pay.
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        $this->actingAs($account->owner)
            ->get(route('subscription.show'))
            ->assertOk();
    });
});

describe('the history', function () {
    it('keeps a superseded choice rather than deleting it', function () {
        // An abandoned choice is history worth keeping, and deleting rows a
        // payment might reference is how orphaned payments happen.
        $package = recordTestPackage();
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        $first = app(SelectPackage::class)->handle($account, $package);
        app(SelectPackage::class)->handle($account, $package);

        expect(UserPackage::query()->whereKey($first->id)->exists())->toBeTrue()
            ->and($first->refresh()->status)->toBe(UserPackageStatus::Superseded);
    });
});
