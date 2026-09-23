<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierOfferPriceChange;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Models\User;
use App\Notifications\Supplier\SupplierOfferSuspended;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The confidentiality boundary D25 draws: the Supplier Rate is visible to its
 * owning Supplier and to staff holding `supplier_pricing.view`, and to nobody
 * else; the platform margin is internal; the Platform Rate is the only figure
 * a Client/Partner ever sees. Amounts are deliberately distinctive so a leak
 * shows up by value, not just by field name.
 */

const SUPPLIER_LEAK_SUPPLIER_RATE = 91234;
const SUPPLIER_LEAK_PLATFORM_RATE = 137777;
const SUPPLIER_LEAK_MARGIN = 46543;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->supplier = Supplier::factory()->create(['business_name' => 'Zulu Traders Ltd']);
    $this->product = websiteTestProduct(['name' => 'Leak test kettle']);
    $this->offer = supplierTestOffer(
        $this->supplier,
        $this->product,
        supplierRate: SUPPLIER_LEAK_SUPPLIER_RATE,
        platformRate: SUPPLIER_LEAK_PLATFORM_RATE,
    );
    $this->manager = testPlatformStaff(PlatformRole::SupplierManager);
});

/**
 * The serialised Inertia page a response carried — every prop the browser
 * would receive *except* the shared translation tree, whose key names
 * (`supplier_rate` as a label) are wording, not data, and ship on every page.
 */
function supplierLeakTestPage(TestResponse $response): string
{
    $page = $response->viewData('page');
    unset($page['props']['translations']);

    return json_encode($page);
}

/** Nothing about the Supplier's cost, the margin, or who the Supplier is. */
function supplierLeakTestAssertClean(string $payload): void
{
    expect($payload)
        ->not->toContain((string) SUPPLIER_LEAK_SUPPLIER_RATE)
        ->not->toContain((string) SUPPLIER_LEAK_MARGIN)
        ->not->toContain('913.34')
        ->not->toContain('supplier_rate')
        ->not->toContain('platform_margin')
        ->not->toContain('Zulu Traders');
}

test('the owning supplier sees its own rate and never the platform rate or margin', function () {
    supplierTestSignIn($this->supplier);

    $response = $this->get(route('supplier.offers.index'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page->component('supplier/offers/index')
        ->where('offers.data.0.supplier_rate.minor_units', SUPPLIER_LEAK_SUPPLIER_RATE));

    expect(supplierLeakTestPage($response))
        ->not->toContain((string) SUPPLIER_LEAK_PLATFORM_RATE)
        ->not->toContain((string) SUPPLIER_LEAK_MARGIN)
        ->not->toContain('platform_rate')
        ->not->toContain('platform_margin');

    $detail = $this->get(route('supplier.offers.show', $this->offer))->assertOk();
    expect(supplierLeakTestPage($detail))
        ->not->toContain((string) SUPPLIER_LEAK_PLATFORM_RATE)
        ->not->toContain('platform_margin');
});

test('another supplier can neither list nor open this supplier\'s offer or rate', function () {
    supplierTestSignIn(Supplier::factory()->create());

    $list = $this->get(route('supplier.offers.index'))->assertOk();
    $list->assertInertia(fn (Assert $page) => $page->has('offers.data', 0));
    expect(supplierLeakTestPage($list))->not->toContain((string) SUPPLIER_LEAK_SUPPLIER_RATE);

    $this->get(route('supplier.offers.show', $this->offer))->assertNotFound();
});

test('the client catalogue shows the platform rate of the preferred offer and nothing of the supplier', function () {
    $account = websiteTestAccount(extra: [
        PackageFeature::WholesaleEnabled->value => '1',
        PackageFeature::DropshippingEnabled->value => '1',
    ]);

    $this->actingAs($this->manager)->post(route('admin.supplier-offers.preferred.store', $this->offer))
        ->assertSessionHasNoErrors();

    $client = $account->owner;

    $browse = $this->actingAs($client)->get(route('catalog.wholesale.index'))->assertOk();
    $show = $this->actingAs($client)->get(route('catalog.wholesale.show', $this->product->slug))->assertOk();

    // Positive control: the platform rate is what a Client/Partner is meant to see.
    expect(supplierLeakTestPage($browse))->toContain((string) SUPPLIER_LEAK_PLATFORM_RATE);
    expect(supplierLeakTestPage($show))->toContain((string) SUPPLIER_LEAK_PLATFORM_RATE);

    supplierLeakTestAssertClean(supplierLeakTestPage($browse));
    supplierLeakTestAssertClean(supplierLeakTestPage($show));

    $dropship = $this->actingAs($client)->get(route('catalog.dropshipping.show', $this->product->slug))->assertOk();
    supplierLeakTestAssertClean(supplierLeakTestPage($dropship));
});

test('a partner website\'s storefront api never carries the supplier rate, margin or supplier identity', function () {
    $account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);
    $website = Website::factory()->forAccount($account)->active()->create();
    [$credential, $secret] = storefrontCredential($website);

    $this->actingAs($this->manager)->post(route('admin.supplier-offers.preferred.store', $this->offer));

    $selection = WebsiteProduct::create([
        'website_id' => $website->id,
        'business_account_id' => $account->id,
        'product_id' => $this->product->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price_minor' => 260000,
        'published_at' => now(),
    ]);

    foreach (['products', 'products/'.$selection->product->public_id] as $path) {
        $response = storefrontCall($credential, $secret, $path)->assertOk();

        supplierLeakTestAssertClean($response->getContent());
        expect($response->getContent())->not->toContain('supplier');
    }
});

test('notifications about an offer carry no rate, margin or internal reason', function () {
    Notification::fake();

    $this->actingAs($this->manager)->post(route('admin.supplier-offers.suspension.store', $this->offer), [
        'reason' => 'Internal: cost dispute at 91234.',
    ])->assertSessionHasNoErrors();

    // The offer's owner is told; the reviewer's private reason is only its note.
    Notification::assertSentTo($this->supplier, SupplierOfferSuspended::class, function ($notification) {
        $payload = json_encode($notification->toArray($this->supplier));

        return ! str_contains($payload, 'platform_rate') && ! str_contains($payload, 'margin');
    });

    // Nobody who is not the Supplier is told anything.
    Notification::assertNothingSentTo($this->manager);
});

test('staff with supplier_pricing.view see the supplier rate and margin', function () {
    $this->actingAs($this->manager)->get(route('admin.supplier-offers.show', $this->offer))
        ->assertInertia(fn (Assert $page) => $page->component('admin/supplier-offers/show')
            ->where('offer.supplier_rate.minor_units', SUPPLIER_LEAK_SUPPLIER_RATE)
            ->where('offer.platform_rate.minor_units', SUPPLIER_LEAK_PLATFORM_RATE)
            ->where('offer.platform_margin.minor_units', SUPPLIER_LEAK_MARGIN));
});

test('pricing permissions are separate and never granted to catalogue roles by default', function (PlatformRole $role) {
    $permissions = $role->permissions();

    expect($permissions)->not->toContain('supplier_pricing.view')
        ->and($permissions)->not->toContain('supplier_pricing.edit');

    $this->actingAs(testPlatformStaff($role))->get(route('admin.supplier-offers.index'))->assertForbidden();
})->with([PlatformRole::ProductManager, PlatformRole::InventoryManager, PlatformRole::Admin]);

test('view without edit can read rates but not change them', function () {
    $viewer = User::factory()->staff()->create();
    $viewer->givePermissionTo('supplier_pricing.view');

    $this->actingAs($viewer)->get(route('admin.supplier-offers.show', $this->offer))->assertOk();

    $this->post(route('admin.supplier-offers.rates.store', $this->offer), [
        'supplier_rate' => '0.01', 'platform_rate' => '0.02', 'reason' => 'x',
    ])->assertForbidden();
    $this->post(route('admin.supplier-offers.suspension.store', $this->offer))->assertForbidden();
    $this->post(route('admin.supplier-offers.preferred.store', $this->offer))->assertForbidden();

    expect($this->offer->refresh()->platform_rate_minor->minorUnits)->toBe(SUPPLIER_LEAK_PLATFORM_RATE);
});

test('a platform rate below the supplier rate is rejected', function () {
    $this->actingAs($this->manager)->post(route('admin.supplier-offers.rates.store', $this->offer), [
        'supplier_rate' => '1000.00', 'platform_rate' => '999.99', 'reason' => 'Try.',
    ])->assertSessionHasErrors('platform_rate');

    expect($this->offer->refresh()->platform_rate_minor->minorUnits)->toBe(SUPPLIER_LEAK_PLATFORM_RATE)
        ->and($this->offer->priceHistory()->count())->toBe(0);
});

test('mismatched currencies, negative rates and a missing reason are rejected', function (array $payload, string $error) {
    $this->actingAs($this->manager)
        ->post(route('admin.supplier-offers.rates.store', $this->offer), $payload)
        ->assertSessionHasErrors($error);

    expect($this->offer->refresh()->supplier_rate_minor->minorUnits)->toBe(SUPPLIER_LEAK_SUPPLIER_RATE);
})->with([
    'currency mismatch' => [['supplier_rate' => '10.00', 'platform_rate' => '20.00', 'supplier_currency_code' => 'BDT', 'platform_currency_code' => 'USD', 'reason' => 'x'], 'platform_rate'],
    'negative supplier rate' => [['supplier_rate' => -1, 'platform_rate' => '20.00', 'reason' => 'x'], 'supplier_rate'],
    'negative platform rate' => [['supplier_rate' => '10.00', 'platform_rate' => -5, 'reason' => 'x'], 'platform_rate'],
    'no reason' => [['supplier_rate' => '10.00', 'platform_rate' => '20.00', 'reason' => ''], 'reason'],
]);

test('every rate change is a new effective-dated version and past versions are never altered', function () {
    $this->actingAs($this->manager);

    $this->post(route('admin.supplier-offers.rates.store', $this->offer), [
        'supplier_rate' => '900.00', 'platform_rate' => '1250.00', 'reason' => 'First change.',
    ])->assertSessionHasNoErrors();

    $this->travel(1)->day();

    $this->post(route('admin.supplier-offers.rates.store', $this->offer), [
        'supplier_rate' => '850.00', 'platform_rate' => '1200.00', 'reason' => 'Second change.',
    ])->assertSessionHasNoErrors();

    $history = $this->offer->priceHistory()->get();

    expect($history)->toHaveCount(2)
        ->and($history->pluck('reason')->all())->toBe(['Second change.', 'First change.'])
        ->and($history->last()->platform_rate_minor->minorUnits)->toBe(125000)
        ->and($history->first()->effective_from->greaterThan($history->last()->effective_from))->toBeTrue()
        ->and($this->offer->refresh()->platform_rate_minor->minorUnits)->toBe(120000)
        ->and(AuditLog::query()->where('action', 'supplier_offer.rates_changed')->count())->toBe(2);

    $first = $history->last();

    expect(fn () => SupplierOfferPriceChange::query()->whereKey($first->id)->update(['reason' => 'edited']))
        ->toThrow(QueryException::class);
    expect(fn () => SupplierOfferPriceChange::query()->whereKey($first->id)->delete())
        ->toThrow(QueryException::class);
});

test('the database itself refuses a platform rate below the supplier rate', function () {
    expect(fn () => SupplierOffer::query()->whereKey($this->offer->id)->update(['platform_rate_minor' => 1]))
        ->toThrow(QueryException::class);
    expect(fn () => SupplierOffer::query()->whereKey($this->offer->id)->update(['supplier_rate_minor' => -1]))
        ->toThrow(QueryException::class);
});

test('a rate change audit never records a secret and does record the reason and both values', function () {
    $this->actingAs($this->manager)->post(route('admin.supplier-offers.rates.store', $this->offer), [
        'supplier_rate' => '900.00', 'platform_rate' => '1250.00', 'reason' => 'Renegotiated for volume.',
    ]);

    $audit = AuditLog::query()->where('action', 'supplier_offer.rates_changed')->firstOrFail();

    expect($audit->reason)->toBe('Renegotiated for volume.')
        ->and($audit->before['platform_rate_minor'])->toBe(SUPPLIER_LEAK_PLATFORM_RATE)
        ->and($audit->after['platform_rate_minor'])->toBe(125000)
        ->and($audit->actor_id)->toBe($this->manager->id);
});

test('suspending one supplier\'s offer leaves another supplier\'s offer on the same product untouched', function () {
    $other = supplierTestOffer(product: $this->product, supplierRate: 95000, platformRate: 140000);

    $this->actingAs($this->manager)->post(route('admin.supplier-offers.suspension.store', $this->offer), ['reason' => 'Late deliveries.'])
        ->assertSessionHasNoErrors();

    expect($this->offer->refresh()->isActive())->toBeFalse()
        ->and($other->refresh()->isActive())->toBeTrue()
        ->and($other->supplier_rate_minor->minorUnits)->toBe(95000)
        ->and(AuditLog::query()->where('action', 'supplier_offer.suspended')->count())->toBe(1);
});
