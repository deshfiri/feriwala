<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Package\Actions\CancelSubscription;
use App\Domain\Package\Actions\SweepSubscriptionLifecycle;
use App\Domain\Package\Data\SubscriptionTerms;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\SubscriptionSource;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Notifications\Package\SubscriptionCancelled;
use App\Notifications\Package\SubscriptionExpired;
use App\Notifications\Package\SubscriptionExpiring;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * The subscription clock, and ending a term early (P1-41, §8.2, §8.4).
 *
 * Renewal due, grace period, expiry — three moves a term has to pass through,
 * each idempotent by transition rather than by a marker table.
 */

function lifecyclePackage(?int $staffLimit = null, int $graceDays = 14): Package
{
    $package = Package::create([
        'slug' => 'life-'.Str::lower(Str::random(8)),
        'name' => 'Growth',
        'fee_minor' => 500000,
        'renewal_fee_minor' => 400000,
        'renewal_frequency' => 'yearly',
        'validity_days' => 365,
        'grace_period_days' => $graceDays,
        'required_deposit_minor' => 0,
        'minimum_balance_minor' => 0,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

    if ($staffLimit !== null) {
        $package->features()->create([
            'feature' => PackageFeature::StaffLimit->value,
            'value' => (string) $staffLimit,
        ]);
    }

    return $package->refresh()->load(['features', 'charges']);
}

/**
 * @return array{0: BusinessAccount, 1: UserPackage}
 */
function lifecycleTerm(
    Package $package,
    UserPackageStatus $status,
    ?string $expiresAt,
    ?string $graceEndsAt = null,
): array {
    $account = BusinessAccount::factory()->create(['status' => AccountStatus::Active]);

    $term = UserPackage::create([
        'business_account_id' => $account->id,
        'package_id' => $package->id,
        'status' => $status,
        'source' => SubscriptionSource::Purchase,
        'started_at' => now()->subDays(300),
        'expires_at' => $expiresAt === null ? null : now()->modify($expiresAt),
        'grace_ends_at' => $graceEndsAt === null ? null : now()->modify($graceEndsAt),
        'paid_fee_minor' => 500000,
        'currency_code' => 'BDT',
        'terms' => SubscriptionTerms::capture($package)->toArray(),
        'terms_captured_at' => now(),
    ]);

    $account->forceFill(['current_user_package_id' => $term->id])->save();

    return [$account->refresh(), $term];
}

describe('the daily sweep', function () {
    it('marks a term due when it comes inside the renewal window', function () {
        $package = lifecyclePackage();
        [, $term] = lifecycleTerm($package, UserPackageStatus::Active, '+10 days', '+24 days');

        $result = app(SweepSubscriptionLifecycle::class)->handle();

        expect($result['due'])->toBe(1)
            ->and($term->refresh()->status)->toBe(UserPackageStatus::RenewalDue)
            // Still entitling: §8.4 restores rather than severs, and a partner
            // with a late invoice still has live orders.
            ->and($term->status->entitles())->toBeTrue();
    });

    it('leaves a term well inside its year alone', function () {
        $package = lifecyclePackage();
        [, $term] = lifecycleTerm($package, UserPackageStatus::Active, '+200 days', '+214 days');

        app(SweepSubscriptionLifecycle::class)->handle();

        expect($term->refresh()->status)->toBe(UserPackageStatus::Active);
    });

    it('moves a term past its end into the grace period', function () {
        $package = lifecyclePackage();
        [, $term] = lifecycleTerm($package, UserPackageStatus::RenewalDue, '-2 days', '+12 days');

        $result = app(SweepSubscriptionLifecycle::class)->handle();

        expect($result['grace'])->toBe(1)
            ->and($term->refresh()->status)->toBe(UserPackageStatus::GracePeriod)
            ->and($term->status->entitles())->toBeTrue();
    });

    it('expires a term once its grace has run out', function () {
        $package = lifecyclePackage();
        [$account, $term] = lifecycleTerm($package, UserPackageStatus::GracePeriod, '-20 days', '-2 days');

        $result = app(SweepSubscriptionLifecycle::class)->handle();

        expect($result['expired'])->toBe(1)
            ->and($term->refresh()->status)->toBe(UserPackageStatus::Expired)
            ->and($term->status->entitles())->toBeFalse()
            // §8.4's feature restriction, by the term changing state rather
            // than by the sweep reaching into other modules.
            ->and(app(Entitlements::class)->activePackage($account->refresh()))->toBeNull();
    });

    it('expires a term with no grace period the moment it ends', function () {
        $package = lifecyclePackage(graceDays: 0);
        [, $term] = lifecycleTerm($package, UserPackageStatus::RenewalDue, '-1 day', null);

        app(SweepSubscriptionLifecycle::class)->handle();

        expect($term->refresh()->status)->toBe(UserPackageStatus::Expired);
    });

    it('expires a term whose end went by while nothing was sweeping', function () {
        /*
         * A fresh deployment, or an outage. The status map allows active to
         * expired directly precisely so this needs no special case.
         */
        $package = lifecyclePackage(graceDays: 0);
        [, $term] = lifecycleTerm($package, UserPackageStatus::Active, '-40 days', null);

        app(SweepSubscriptionLifecycle::class)->handle();

        expect($term->refresh()->status)->toBe(UserPackageStatus::Expired);
    });

    it('does nothing the second time it runs', function () {
        // Idempotent by transition: a repeat pass finds the term already moved.
        $package = lifecyclePackage();
        lifecycleTerm($package, UserPackageStatus::Active, '+10 days', '+24 days');

        $first = app(SweepSubscriptionLifecycle::class)->handle();
        $second = app(SweepSubscriptionLifecycle::class)->handle();

        expect($first['due'])->toBe(1)
            ->and($second)->toBe(['due' => 0, 'grace' => 0, 'expired' => 0]);
    });

    it('sends one message per move, however often it runs', function () {
        Notification::fake();

        $package = lifecyclePackage();
        [$account] = lifecycleTerm($package, UserPackageStatus::Active, '+10 days', '+24 days');

        app(SweepSubscriptionLifecycle::class)->handle();
        app(SweepSubscriptionLifecycle::class)->handle();

        Notification::assertSentToTimes($account->owner, SubscriptionExpiring::class, 1);
    });

    it('tells the account when its term has run out', function () {
        Notification::fake();

        $package = lifecyclePackage();
        [$account] = lifecycleTerm($package, UserPackageStatus::GracePeriod, '-20 days', '-2 days');

        app(SweepSubscriptionLifecycle::class)->handle();

        Notification::assertSentTo($account->owner, SubscriptionExpired::class);
    });

    it('moves the pointer onto a successor that has started', function () {
        /*
         * A renewal paid for weeks ago and starting exactly now. Leaving the
         * pointer on the term that just expired would make the account look
         * lapsed on every screen that reads it.
         */
        $package = lifecyclePackage(graceDays: 0);
        [$account, $expiring] = lifecycleTerm($package, UserPackageStatus::Active, '-1 day', null);

        $successor = UserPackage::create([
            'business_account_id' => $account->id,
            'package_id' => $package->id,
            'status' => UserPackageStatus::Active,
            'source' => SubscriptionSource::Renewal,
            'started_at' => now()->subHour(),
            'expires_at' => now()->addDays(365),
            'paid_fee_minor' => 400000,
            'currency_code' => 'BDT',
            'terms' => SubscriptionTerms::capture($package)->toArray(),
            'terms_captured_at' => now(),
            'renews_user_package_id' => $expiring->id,
        ]);

        app(SweepSubscriptionLifecycle::class)->handle();

        expect($expiring->refresh()->status)->toBe(UserPackageStatus::Expired)
            ->and($account->refresh()->current_user_package_id)->toBe($successor->id);
    });

    it('leaves a term that never expires alone', function () {
        $package = lifecyclePackage();
        [, $term] = lifecycleTerm($package, UserPackageStatus::Active, null, null);

        app(SweepSubscriptionLifecycle::class)->handle();

        expect($term->refresh()->status)->toBe(UserPackageStatus::Active);
    });
});

describe('cancelling a term', function () {
    it('stops the package now and says so in the record', function () {
        $package = lifecyclePackage();
        [$account, $term] = lifecycleTerm($package, UserPackageStatus::Active, '+100 days', '+114 days');

        app(CancelSubscription::class)->handle($term, $account->owner, 'Closing the shop for the season.');

        expect($term->refresh()->status)->toBe(UserPackageStatus::Cancelled)
            ->and($term->cancelled_at)->not->toBeNull()
            ->and($term->status->entitles())->toBeFalse()
            // The pointer does not keep naming a term that grants nothing.
            ->and($account->refresh()->current_user_package_id)->toBeNull();
    });

    it('records who cancelled and why', function () {
        $package = lifecyclePackage();
        [$account, $term] = lifecycleTerm($package, UserPackageStatus::Active, '+100 days', '+114 days');

        app(CancelSubscription::class)->handle($term, $account->owner, 'Closing the shop for the season.');

        $entry = AuditLog::query()->where('action', 'package.cancelled')->firstOrFail();

        expect($entry->actor_id)->toBe($account->owner->id)
            ->and($entry->reason)->toBe('Closing the shop for the season.')
            ->and($entry->is_sensitive)->toBeTrue();
    });

    it('requires a reason', function () {
        $package = lifecyclePackage();
        [$account, $term] = lifecycleTerm($package, UserPackageStatus::Active, '+100 days', '+114 days');

        expect(fn () => app(CancelSubscription::class)->handle($term, $account->owner, ' '))
            ->toThrow(InvalidArgumentException::class);
    });

    it('refuses a term that has already closed', function () {
        // Through the status machine: cancelled, expired and superseded are
        // terminal, and cancelling one twice would rewrite history.
        $package = lifecyclePackage();
        [$account, $term] = lifecycleTerm($package, UserPackageStatus::Active, '+100 days', '+114 days');

        app(CancelSubscription::class)->handle($term, $account->owner, 'Closing the shop.');

        expect(fn () => app(CancelSubscription::class)->handle($term->refresh(), $account->owner, 'Again.'))
            ->toThrow(RuntimeException::class);
    });

    it('tells the account holder', function () {
        Notification::fake();

        $package = lifecyclePackage();
        [$account, $term] = lifecycleTerm($package, UserPackageStatus::Active, '+100 days', '+114 days');

        app(CancelSubscription::class)->handle($term, $account->owner, 'Closing the shop.');

        Notification::assertSentTo($account->owner, SubscriptionCancelled::class);
    });

    it('is reachable from the account\'s own screen and nobody else\'s', function () {
        $package = lifecyclePackage();
        [$account, $term] = lifecycleTerm($package, UserPackageStatus::Active, '+100 days', '+114 days');
        [$stranger] = lifecycleTerm($package, UserPackageStatus::Active, '+100 days', '+114 days');

        $this->actingAs($account->owner)
            ->post(route('subscription.cancel'), ['reason' => 'Closing the shop for the season.'])
            ->assertRedirect();

        expect($term->refresh()->status)->toBe(UserPackageStatus::Cancelled)
            // Self-scoped: the term is found through the caller's own account,
            // so there is no identifier to substitute (§31.3).
            ->and($stranger->refresh()->currentPackage()->first()?->status)
            ->toBe(UserPackageStatus::Active);
    });

    it('refuses a blank reason at the route as well as in the action', function () {
        $package = lifecyclePackage();
        [$account] = lifecycleTerm($package, UserPackageStatus::Active, '+100 days', '+114 days');

        $this->actingAs($account->owner)
            ->post(route('subscription.cancel'), ['reason' => ''])
            ->assertSessionHasErrors('reason');
    });
});
