<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The dashboard is business ERP, and `business.activated` decides who reaches
 * it: an activated account gets in, an unactivated one is sent to the stepper,
 * and platform staff — who have no business account at all — are sent to their
 * own queues (D23). These tests hold that boundary as much as the content.
 *
 * Helpers are prefixed because Pest loads every test file into one global
 * function namespace; a bare `settledPayment()` would collide with the same
 * name anywhere else in the suite.
 */

/**
 * An owner whose account sits at `$status`, which must be one the §5.4 gate
 * admits.
 */
function dashboardOwner(AccountStatus $status = AccountStatus::Active): User
{
    return User::factory()
        ->withBusinessAccount(fn ($account) => $account->onboarding($status))
        ->create();
}

/**
 * A settled payment against `$accountId`, `$monthsAgo` months back.
 */
function dashboardSettledPayment(
    int $accountId,
    int $minorUnits,
    int $monthsAgo = 0,
    PaymentPurpose $purpose = PaymentPurpose::Activation,
): Payment {
    return Payment::create([
        'business_account_id' => $accountId,
        'purpose' => $purpose,
        'status' => PaymentStatus::Paid,
        'amount_minor' => $minorUnits,
        'currency_code' => 'BDT',
        'completed_at' => now()->subMonths($monthsAgo),
    ]);
}

describe('who reaches the dashboard at all', function () {
    it('turns away an account still in the funnel', function () {
        $user = dashboardOwner(AccountStatus::KycPending);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('onboarding.status'));
    });

    it('sends platform staff to their own queues instead', function () {
        $this->seed(RolesAndPermissionsSeeder::class);

        // D23: no business account, so the business ERP is not theirs to open.
        // The dashboard therefore never has to render an admin panel.
        $this->actingAs(testPlatformStaff(PlatformRole::KycManager))
            ->get(route('dashboard'))
            ->assertRedirect(route('admin.kyc.index'));
    });
});

describe('an account holder', function () {
    it('is greeted by first name only', function () {
        $user = User::factory()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create(['name' => 'Mohammad Abdul Karim']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                // A greeting, not a form field.
                ->where('greeting.name', 'Mohammad')
                ->has('greeting.period'),
            );
    });

    it('is told plainly that nothing needs attention', function () {
        $this->actingAs(dashboardOwner(AccountStatus::Active))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('standing.needsAttention', false)
                ->where('standing.action', null)
                ->has('standing.label')
                ->has('standing.tone'),
            );
    });

    it('leads with a lapsed package and offers the one thing to do', function (AccountStatus $status) {
        // These statuses pass the §5.4 gate, so the account is on this page
        // rather than in the funnel — and saying so is the most useful thing
        // the dashboard does that day (§33.3).
        $this->actingAs(dashboardOwner($status))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('standing.status', $status->value)
                ->where('standing.needsAttention', true)
                ->where('standing.action.href', route('packages.index')),
            );
    })->with([
        'renewal due' => AccountStatus::PackageRenewalDue,
        'expired' => AccountStatus::PackageExpired,
    ]);

    it('states a problem it has no screen for without offering a dead button', function () {
        // The wallet screens are not built. A button leading nowhere is worse
        // than a status that simply states the problem.
        $this->actingAs(dashboardOwner(AccountStatus::LowWalletBalance))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('standing.needsAttention', true)
                ->where('standing.action', null),
            );
    });

    it('counts only settled payments towards what they have spent', function () {
        $user = dashboardOwner();
        $accountId = $user->businessAccount->id;

        dashboardSettledPayment($accountId, 600000);

        // Pending money looks like success on a gateway's redirect page and is
        // not money here. Counting it would tell someone they had spent
        // something they may yet not.
        Payment::create([
            'business_account_id' => $accountId,
            'purpose' => PaymentPurpose::PackageRenewal,
            'status' => PaymentStatus::Pending,
            'amount_minor' => 999900,
            'currency_code' => 'BDT',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                // Deferred: absent from the first response by design.
                ->missing('spend')
                ->loadDeferredProps(fn (Assert $loaded) => $loaded
                    ->where('spend.total.minor_units', 600000)
                    ->where('spend.total.currency', 'BDT'),
                ),
            );
    });

    it('keeps a month with no payments on the axis', function () {
        $user = dashboardOwner();
        dashboardSettledPayment($user->businessAccount->id, 250000);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $loaded) => $loaded
                    // Six buckets, five of them empty. Dropping the empty ones
                    // would compress the timeline and make a gap look like a
                    // continuous run.
                    ->has('spend.series.0.points', 6)
                    ->where('spend.months', 6),
                ),
            );
    });

    it('sends the y-axis labels already formatted as money', function () {
        $user = dashboardOwner();
        dashboardSettledPayment($user->businessAccount->id, 400000);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $loaded) => $loaded
                    // Nothing divides by a hundred in JavaScript (§36.1, D4):
                    // the axis arrives carrying its own words.
                    ->where('spend.ticks.0.value', 0)
                    ->where('spend.ticks.0.label', '৳0.00')
                    ->where('spend.series.0.points.5.formatted', '৳4,000.00'),
                ),
            );
    });

    it('splits spend by what it was for, largest first', function () {
        $user = dashboardOwner();
        $accountId = $user->businessAccount->id;

        dashboardSettledPayment($accountId, 100000, 0, PaymentPurpose::Activation);
        dashboardSettledPayment($accountId, 400000, 1, PaymentPurpose::PackageRenewal);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $loaded) => $loaded
                    // Biggest cost read first.
                    ->where('spend.breakdown.0.key', 'package_renewal')
                    ->where('spend.breakdown.0.value', 400000)
                    ->where('spend.breakdown.1.key', 'activation'),
                ),
            );
    });

    it('never sees another account\'s spend', function () {
        $mine = dashboardOwner();
        $theirs = dashboardOwner();

        dashboardSettledPayment($theirs->businessAccount->id, 5000000);

        // Self-scoped by construction (§31.3): the account comes from the
        // signed-in person's membership, so there is no parameter to tamper
        // with — but the figure is worth pinning down anyway.
        $this->actingAs($mine)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $loaded) => $loaded
                    ->where('spend.total.minor_units', 0),
                ),
            );
    });

    it('leaves an account with no payments an empty chart rather than a broken one', function () {
        $this->actingAs(dashboardOwner())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $loaded) => $loaded
                    ->where('spend.total.minor_units', 0)
                    ->where('spend.breakdown', [])
                    // One tick at zero: an axis, not an empty array the chart
                    // would have to guess a scale from.
                    ->has('spend.ticks', 1),
                ),
            );
    });
});
