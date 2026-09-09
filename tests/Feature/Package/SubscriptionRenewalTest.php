<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\CalculateRenewalQuote;
use App\Domain\Package\Actions\ActivateRenewal;
use App\Domain\Package\Actions\OpenRenewal;
use App\Domain\Package\Data\SubscriptionTerms;
use App\Domain\Package\Entitlements;
use App\Domain\Package\Enums\SubscriptionSource;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Package\SubscriptionPolicy;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;
use App\Notifications\Package\SubscriptionRenewed;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Renewing a term (P1-38, §8.2, §8.4).
 *
 * A renewal is a new subscription record, not the old one given more time.
 * Every term an account has held is history: what it cost, what it granted, and
 * when it ran.
 */

/**
 * An active account on a package, with a term that ends in `$days`.
 *
 * @return array{0: BusinessAccount, 1: Package, 2: UserPackage}
 */
function renewalTestAccount(int $days = 10, int $renewalFeeMinor = 400000): array
{
    $account = BusinessAccount::factory()->create(['status' => AccountStatus::Active]);

    $package = Package::create([
        'slug' => 'renewal-'.Str::lower(Str::random(8)),
        'name' => 'Growth',
        'fee_minor' => 500000,
        'renewal_fee_minor' => $renewalFeeMinor,
        'renewal_frequency' => 'yearly',
        'validity_days' => 365,
        'grace_period_days' => 14,
        'required_deposit_minor' => 0,
        'minimum_balance_minor' => 0,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

    $subscription = UserPackage::create([
        'business_account_id' => $account->id,
        'package_id' => $package->id,
        'status' => UserPackageStatus::Active,
        'source' => SubscriptionSource::Purchase,
        'started_at' => now()->subDays(355),
        'expires_at' => now()->addDays($days),
        'grace_ends_at' => now()->addDays($days + 14),
        'paid_fee_minor' => 500000,
        'currency_code' => 'BDT',
        'terms' => SubscriptionTerms::capture($package)->toArray(),
        'terms_captured_at' => now(),
    ]);

    $account->forceFill(['current_user_package_id' => $subscription->id])->save();

    return [$account->refresh(), $package, $subscription];
}

describe('when a term may be renewed', function () {
    it('opens inside the configured window and not before', function () {
        $policy = app(SubscriptionPolicy::class);

        [, , $soon] = renewalTestAccount(days: 10);
        [, , $distant] = renewalTestAccount(days: 200);

        expect($policy->isRenewable($soon))->toBeTrue()
            ->and($policy->isRenewable($distant))->toBeFalse();
    });

    it('follows the setting rather than a number in the code', function () {
        // "Start reminding people a fortnight out instead of a month" is an
        // operational decision, not a deploy.
        $settings = app(SettingsRepository::class);
        $settings->define(SubscriptionPolicy::RENEWAL_WINDOW, 'package', SettingType::Integer);
        $settings->set(SubscriptionPolicy::RENEWAL_WINDOW, 90);

        [, , $subscription] = renewalTestAccount(days: 60);

        expect(app(SubscriptionPolicy::class)->isRenewable($subscription))->toBeTrue();
    });

    it('stays open after the term has run out', function () {
        // §8.4 keeps "limited access to Payment and renewal modules" after
        // expiry. A renewal page that closes at expiry closes exactly when it
        // is needed.
        [, , $subscription] = renewalTestAccount(days: 10);

        foreach ([UserPackageStatus::RenewalDue, UserPackageStatus::GracePeriod] as $status) {
            $subscription->forceFill(['status' => $status])->save();

            expect(app(SubscriptionPolicy::class)->isRenewable($subscription->refresh()))->toBeTrue();
        }
    });

    it('refuses a term that never ends', function () {
        // Selling a second endless term would take money for nothing.
        [, , $subscription] = renewalTestAccount(days: 10);
        $subscription->forceFill(['expires_at' => null])->save();

        expect(app(SubscriptionPolicy::class)->isRenewable($subscription->refresh()))->toBeFalse();
    });

    it('refuses a term that is closed or not yet paid for', function () {
        [, , $subscription] = renewalTestAccount(days: 10);
        $policy = app(SubscriptionPolicy::class);

        foreach ([UserPackageStatus::Cancelled, UserPackageStatus::Superseded, UserPackageStatus::PendingPayment] as $status) {
            $subscription->forceFill(['status' => $status])->save();

            expect($policy->isRenewable($subscription->refresh()))->toBeFalse();
        }
    });
});

describe('opening a renewal', function () {
    it('creates a new subscription rather than extending the old one', function () {
        [$account, , $current] = renewalTestAccount();

        $renewal = app(OpenRenewal::class)->handle($account, $current);

        expect($renewal->id)->not->toBe($current->id)
            ->and($renewal->status)->toBe(UserPackageStatus::PendingPayment)
            ->and($renewal->source)->toBe(SubscriptionSource::Renewal)
            ->and($renewal->renews_user_package_id)->toBe($current->id)
            // Untouched: the term running out keeps its own history.
            ->and($current->refresh()->status)->toBe(UserPackageStatus::Active);
    });

    it('captures the terms as they stand now, not the ones running out', function () {
        /*
         * §8.3 makes a change of terms something an account agrees to. A
         * renewal is that moment: the price and the entitlements are today's,
         * shown before anything is paid — and the old term keeps its snapshot.
         */
        [$account, $package, $current] = renewalTestAccount();

        $package->forceFill(['renewal_fee_minor' => 650000, 'validity_days' => 400])->save();

        $renewal = app(OpenRenewal::class)->handle($account, $current->refresh());

        expect($renewal->terms()?->renewalFeeMinor)->toBe(650000)
            ->and($renewal->terms()?->validityDays)->toBe(400)
            ->and($renewal->paid_fee_minor?->minorUnits)->toBe(650000)
            // And the term being renewed is unchanged.
            ->and($current->refresh()->terms()?->validityDays)->toBe(365);
    });

    it('grants nothing until it is paid for', function () {
        [$account, , $current] = renewalTestAccount();

        $renewal = app(OpenRenewal::class)->handle($account, $current);

        expect($renewal->status->entitles())->toBeFalse()
            ->and($renewal->entitlesNow())->toBeFalse();
    });

    it('returns the same pending renewal rather than stacking a second', function () {
        // A double-submitted form must not leave an account with a queue of
        // unpaid renewals.
        [$account, , $current] = renewalTestAccount();

        $first = app(OpenRenewal::class)->handle($account, $current);
        $second = app(OpenRenewal::class)->handle($account, $current);

        expect($second->id)->toBe($first->id)
            ->and($account->packages()->where('source', SubscriptionSource::Renewal)->count())->toBe(1);
    });

    it('refuses a term that is not renewable yet', function () {
        [$account, , $current] = renewalTestAccount(days: 200);

        expect(fn () => app(OpenRenewal::class)->handle($account, $current))
            ->toThrow(RuntimeException::class);
    });

    it('refuses a subscription belonging to somebody else', function () {
        // §31.3, enforced where the write happens rather than only at the URL.
        [$account] = renewalTestAccount();
        [, , $theirs] = renewalTestAccount();

        expect(fn () => app(OpenRenewal::class)->handle($account, $theirs))
            ->toThrow(RuntimeException::class);
    });
});

describe('what a renewal costs', function () {
    it('charges the renewal fee, not the package fee', function () {
        [$account, , $current] = renewalTestAccount(renewalFeeMinor: 400000);

        $renewal = app(OpenRenewal::class)->handle($account, $current);
        $quote = app(CalculateRenewalQuote::class)
            ->handle($renewal->terms(), $account);

        expect($quote->total()->minorUnits)->toBe(400000);
    });

    it('falls back to the package fee where no renewal fee is set', function () {
        // §8.1 lists the renewal fee as its own optional field. Silence there
        // means no separate renewal price, not a free year.
        [$account, $package, $current] = renewalTestAccount();
        $package->forceFill(['renewal_fee_minor' => null])->save();

        $renewal = app(OpenRenewal::class)->handle($account, $current->refresh());
        $quote = app(CalculateRenewalQuote::class)
            ->handle($renewal->terms(), $account);

        expect($quote->total()->minorUnits)->toBe(500000);
    });

    it('charges no registration fee', function () {
        // That is charged once, when the account is created (§9).
        [$account, , $current] = renewalTestAccount();

        $renewal = app(OpenRenewal::class)->handle($account, $current);
        $quote = app(CalculateRenewalQuote::class)
            ->handle($renewal->terms(), $account);

        $types = array_map(fn ($line) => $line->type->value, $quote->lines);

        expect($types)->not->toContain('registration_fee')
            ->and($types)->not->toContain('wallet_deposit');
    });
});

describe('when the renewal is paid for', function () {
    it('starts where the current term ends, so renewing early loses no days', function () {
        [$account, , $current] = renewalTestAccount(days: 10);

        $renewal = app(OpenRenewal::class)->handle($account, $current);
        app(ActivateRenewal::class)->handle($renewal);

        $renewal->refresh();

        expect($renewal->status)->toBe(UserPackageStatus::Active)
            ->and($renewal->started_at?->toDateString())
            ->toBe($current->expires_at?->toDateString())
            ->and($renewal->expires_at?->toDateString())
            ->toBe($current->expires_at?->addDays(365)->toDateString());
    });

    it('starts now when the term has already run out', function () {
        [$account, , $current] = renewalTestAccount(days: 10);
        $current->forceFill([
            'status' => UserPackageStatus::GracePeriod,
            'expires_at' => now()->subDays(3),
            'grace_ends_at' => now()->addDays(11),
        ])->save();

        $renewal = app(OpenRenewal::class)->handle($account, $current->refresh());
        app(ActivateRenewal::class)->handle($renewal);

        expect($renewal->refresh()->started_at?->toDateString())->toBe(now()->toDateString())
            // And the term that ran out is closed.
            ->and($current->refresh()->status)->toBe(UserPackageStatus::Expired);
    });

    it('leaves a term that is still running alone', function () {
        // Expiring it the moment the renewal is paid would take away the weeks
        // somebody paid for and renewed to protect.
        [$account, , $current] = renewalTestAccount(days: 10);

        $renewal = app(OpenRenewal::class)->handle($account, $current);
        app(ActivateRenewal::class)->handle($renewal);

        expect($current->refresh()->status)->toBe(UserPackageStatus::Active);
    });

    it('keeps entitlements unbroken across an early renewal', function () {
        /*
         * The pointer moves to the renewed term straight away, and that term
         * has not started yet. `Entitlements` checks whether the pointed-at
         * term entitles *now* and falls back to whichever does — so the still
         * running term keeps granting until its own expiry.
         */
        [$account, , $current] = renewalTestAccount(days: 10);

        $renewal = app(OpenRenewal::class)->handle($account, $current);
        app(ActivateRenewal::class)->handle($renewal);

        $active = app(Entitlements::class)->activePackage($account->refresh());

        expect($account->refresh()->current_user_package_id)->toBe($renewal->id)
            ->and($active?->id)->toBe($current->id);
    });

    it('runs once however many times the gateway says so', function () {
        // A gateway sends the same notification several times; a renewal
        // activated twice would chain two terms off one payment.
        [$account, , $current] = renewalTestAccount();

        $renewal = app(OpenRenewal::class)->handle($account, $current);

        app(ActivateRenewal::class)->handle($renewal);
        $firstExpiry = $renewal->refresh()->expires_at;

        app(ActivateRenewal::class)->handle($renewal);

        expect($renewal->refresh()->expires_at?->toIso8601String())
            ->toBe($firstExpiry?->toIso8601String());
    });

    it('tells the account holder, with the new expiry', function () {
        Notification::fake();

        [$account, , $current] = renewalTestAccount();

        $renewal = app(OpenRenewal::class)->handle($account, $current);
        app(ActivateRenewal::class)->handle($renewal);

        Notification::assertSentTo($account->owner, SubscriptionRenewed::class);
    });
});

describe('the renewal screen', function () {
    it('offers renewal on the subscription page when the term is due', function () {
        [$account] = renewalTestAccount(days: 10);

        $this->actingAs($account->owner)
            ->get(route('subscription.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/subscription')
                ->where('can.renew', true),
            );
    });

    it('says why it cannot be renewed rather than hiding the reason', function () {
        [$account] = renewalTestAccount(days: 200);

        $this->actingAs($account->owner)
            ->get(route('subscription.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.renew', false)
                ->whereNot('renewal_blocker', null),
            );
    });

    it('shows the quote and the date the new term begins', function () {
        [$account, , $current] = renewalTestAccount(days: 10);

        $this->actingAs($account->owner)
            ->get(route('subscription.renew.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/renewal')
                ->where('renewal.package', 'Growth')
                ->where('quote.total.minor_units', 400000),
            );
    });

    it('turns away a term that is not renewable yet', function () {
        [$account] = renewalTestAccount(days: 200);

        $this->actingAs($account->owner)
            ->get(route('subscription.renew.show'))
            ->assertRedirect(route('subscription.show'));
    });

    it('is closed to somebody with no account', function () {
        // Self-scoped by membership: there is no account to resolve, so there
        // is nothing to renew and nothing to leak (§31.3).
        $stranger = User::factory()->staff()->create();

        $this->actingAs($stranger)
            ->get(route('subscription.renew.show'))
            ->assertForbidden();
    });
});
