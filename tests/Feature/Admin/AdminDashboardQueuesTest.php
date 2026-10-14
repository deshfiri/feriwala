<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Supplier\Enums\StockUpdateStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The queue cards and the submissions trend on the admin dashboard.
 *
 * Every figure is gated on the same view permission as the screen it links to,
 * and the trend counts only the queues its viewer may open — so neither a card
 * nor the chart can reveal volume in a queue the viewer cannot see.
 *
 * Helpers are prefixed because Pest loads every test file into one global
 * function namespace.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Two availability updates, one still pending and one already decided.
 */
function dashboardQueueStockUpdates(): void
{
    $offer = supplierTestOffer(Supplier::factory()->create());

    foreach ([StockUpdateStatus::Pending, StockUpdateStatus::Approved] as $status) {
        $offer->stockUpdates()->create(['requested_quantity' => 5, 'status' => $status]);
    }
}

/**
 * A KYC round waiting on review and one already decided.
 */
function dashboardQueueKycRounds(): void
{
    $account = testBusinessAccount(AccountStatus::KycPending);

    KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Submitted, 'round' => 1]);
    KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Approved, 'round' => 2]);
}

/**
 * @return array<string, int> card key => value
 */
function dashboardQueueCards(Assert $page): array
{
    $cards = [];

    foreach ($page->toArray()['props']['cards'] as $card) {
        $cards[$card['key']] = $card['value'];
    }

    return $cards;
}

it('counts what is waiting in each new queue, and ignores what is already decided', function () {
    dashboardQueueStockUpdates();
    Website::factory()->count(2)->create(['status' => WebsiteStatus::SetupPending]);
    Website::factory()->active()->create();

    $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
        ->get(route('admin.dashboard'))
        ->assertInertia(function (Assert $page) {
            $cards = dashboardQueueCards($page);

            expect($cards)->toHaveKeys([
                'supplier_listing_lots', 'supplier_stock', 'account_withdrawals',
                'supplier_withdrawals', 'returns', 'refunds', 'website_requests',
            ])
                ->and($cards['supplier_stock'])->toBe(1)
                ->and($cards['website_requests'])->toBe(2)
                ->and($cards['refunds'])->toBe(0);
        });
});

it('keeps the cards that were already there', function () {
    $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => expect(dashboardQueueCards($page))
            ->toHaveKeys(['kyc', 'activations', 'orders', 'supplier_kyc', 'supplier_listings']));
});

it('hides a queue card from someone who cannot open that queue', function () {
    dashboardQueueStockUpdates();

    // Payment Manager can see refunds but has no supplier-stock permission.
    $this->actingAs(testPlatformStaff(PlatformRole::PaymentManager))
        ->get(route('admin.dashboard'))
        ->assertInertia(function (Assert $page) {
            $cards = dashboardQueueCards($page);

            expect($cards)->toHaveKey('refunds')
                ->and($cards)->not->toHaveKey('supplier_stock')
                ->and($cards)->not->toHaveKey('website_requests');
        });
});

/**
 * Today's figure on the submissions series, as the viewer sees it.
 */
function dashboardQueueSubmissionsToday(Assert $page): int
{
    return collect($page->toArray()['props']['trend'])->firstWhere('key', 'submissions')['points'][13]['value'];
}

it('counts submissions received per day, from the queues the viewer may open only', function () {
    dashboardQueueKycRounds();      // two partner KYC rows arrived today
    dashboardQueueStockUpdates();   // two availability updates arrived today

    $viewer = function (string ...$permissions) {
        $staff = testPlatformStaff(PlatformRole::SmsManager);
        $staff->givePermissionTo($permissions);

        return $staff;
    };

    // Someone who can open only the KYC queue is counted only the KYC rows …
    $this->actingAs($viewer('kyc.view'))->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => expect(dashboardQueueSubmissionsToday($page))->toBe(2));

    // … only the stock queue, only the stock updates …
    $this->actingAs($viewer('supplier_stock.view'))->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => expect(dashboardQueueSubmissionsToday($page))->toBe(2));

    // … both, both …
    $this->actingAs($viewer('kyc.view', 'supplier_stock.view'))->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => expect(dashboardQueueSubmissionsToday($page))->toBe(4));

    // … and a Super Admin everything.
    $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => expect(dashboardQueueSubmissionsToday($page))->toBe(4));
});
it('gives the submissions trend 14 days, today last', function () {
    $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('trend', 2)
            ->where('trend.1.key', 'submissions')
            ->has('trend.1.points', 14),
        );
});

it('gives no submissions trend to staff who can open none of the queues', function () {
    dashboardQueueKycRounds();

    $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('trend', null));
});

it('has English and Bangla text for every card and the trend', function () {
    foreach (['en', 'bn'] as $locale) {
        foreach (['supplier_listing_lots', 'supplier_stock', 'account_withdrawals', 'supplier_withdrawals', 'returns', 'refunds', 'website_requests'] as $key) {
            expect(trans("dashboard.admin.cards.$key", [], $locale))->not->toBe("dashboard.admin.cards.$key");
        }

        expect(trans('dashboard.admin.trend.submissions', [], $locale))->not->toBe('dashboard.admin.trend.submissions');
    }
});
