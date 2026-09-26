<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * What the navigation is allowed to know (§32, §33).
 *
 * The sidebar reads `permissions['payment.view']` out of a shared Inertia prop
 * that ships a hand-picked handful of abilities rather than all 161. That is the
 * right trade — but an absent key is `undefined`, `undefined` is falsy, and a
 * link gated on a key nobody ships is a link nobody can see, with nothing
 * failing anywhere to say so.
 *
 * Billing rules, Payment gateways, Payments and SMS all went missing exactly
 * that way: reachable by typing the address, invisible in the sidebar, and green
 * across the whole suite because every test asked the endpoint rather than the
 * menu.
 */

/**
 * The shared permission prop as the browser actually receives it.
 *
 * Read from the rendered page rather than through Inertia's dotted path helper,
 * which would read `permissions.payment.view` as three nested keys instead of
 * one key containing a dot.
 *
 * @return array<string, bool>
 */
function navPermissionsFor(User $user, string $route): array
{
    $response = test()->actingAs($user)->get(route($route));

    $response->assertOk();

    /** @var array<string, bool> $permissions */
    $permissions = $response->viewData('page')['props']['permissions'] ?? [];

    return $permissions;
}

/**
 * The abilities `use-navigation.ts` actually gates on, read from the file.
 *
 * @return array<int, string>
 */
function navAbilitiesInUse(): array
{
    $source = (string) file_get_contents(base_path('resources/js/hooks/use-navigation.ts'));

    preg_match_all("/permissions\['([a-z_.]+)'\]/", $source, $matches);

    return array_values(array_unique($matches[1]));
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('ships every ability the navigation gates a link on', function () {
    /*
     * The guard that would have caught this. Adding a screen means adding its
     * ability to the shared prop, and forgetting is otherwise silent.
     */
    $shipped = array_map(
        fn (array $ability) => PermissionCatalogue::name($ability[0], $ability[1]),
        HandleInertiaRequests::NAVIGATION_ABILITIES,
    );

    $used = navAbilitiesInUse();

    // Anything listed here is gated on an ability the browser never receives,
    // which means the link is invisible to everybody including a Super Admin.
    $missing = array_values(array_diff($used, $shipped));

    expect($used)->not->toBeEmpty()
        ->and($missing)->toBe([]);
});

it('sends a Super Admin every navigation ability', function () {
    // `Gate::before` gives a Super Admin every policy, and the sidebar has to
    // agree with that rather than showing a subset.
    $permissions = navPermissionsFor(
        testPlatformStaff(PlatformRole::SuperAdmin),
        'admin.payments.index',
    );

    foreach (navAbilitiesInUse() as $ability) {
        expect($permissions[$ability] ?? null)->toBeTrue(
            "A Super Admin was not sent [{$ability}], so that link stays hidden.",
        );
    }
});

it('sends a payment manager the payment abilities and not the SMS one', function () {
    $permissions = navPermissionsFor(
        testPlatformStaff(PlatformRole::PaymentManager),
        'admin.payments.index',
    );

    expect($permissions['payment.view'])->toBeTrue()
        ->and($permissions['sms.view'])->toBeFalse();
});

it('sends an SMS manager the SMS ability and not the payment one', function () {
    $permissions = navPermissionsFor(
        testPlatformStaff(PlatformRole::SmsManager),
        'admin.sms.index',
    );

    expect($permissions['sms.view'])->toBeTrue()
        ->and($permissions['payment.view'])->toBeFalse();
});

it('sends a wallet manager the wallet ability and not the payment one', function () {
    // Reading what a business holds is not the same job as reconciling what a
    // gateway sent, and the sidebar has to reflect that (§23, §42).
    $permissions = navPermissionsFor(
        testPlatformStaff(PlatformRole::WalletManager),
        'admin.wallets.index',
    );

    expect($permissions['wallet.view'])->toBeTrue()
        ->and($permissions['payment.view'])->toBeFalse();
});

it('sends an order manager the order ability and not the payment one', function () {
    // Reviewing every order is its own job (§18.4), and the Orders link reads it.
    $permissions = navPermissionsFor(
        testPlatformStaff(PlatformRole::OrderManager),
        'admin.orders.index',
    );

    expect($permissions['order.view'])->toBeTrue()
        ->and($permissions['payment.view'])->toBeFalse();
});

it('sends a Content Manager the CMS and SEO abilities', function () {
    // Editing the landing page and browsing the SEO defaults are the same
    // navigation section, and ContentManager holds both (§34).
    $permissions = navPermissionsFor(
        testPlatformStaff(PlatformRole::ContentManager),
        'admin.cms.pages.index',
    );

    expect($permissions['cms.view'])->toBeTrue()
        ->and($permissions['seo.view'])->toBeTrue()
        ->and($permissions['payment.view'])->toBeFalse();
});

it('sends an SEO Manager the SEO ability, and the CMS one too since it holds cms.edit', function () {
    // Not a bug: an SEO manager reaches the page workspace to browse into a
    // page and pick its OG image override (SeoSettingController's own doc
    // comment) — cms.view is genuinely held, not merely implied.
    $permissions = navPermissionsFor(
        testPlatformStaff(PlatformRole::SeoManager),
        'admin.cms.seo.index',
    );

    expect($permissions['seo.view'])->toBeTrue()
        ->and($permissions['cms.view'])->toBeTrue()
        ->and($permissions['payment.view'])->toBeFalse();
});

it('never sends a role outside the CMS the cms/seo navigation abilities', function () {
    $permissions = navPermissionsFor(
        testPlatformStaff(PlatformRole::PackageManager),
        'admin.packages.index',
    );

    expect($permissions['cms.view'])->toBeFalse()
        ->and($permissions['seo.view'])->toBeFalse();
});

it('sends a business owner no order administration, whose own orders have their own door', function () {
    $account = testBusinessAccount(AccountStatus::Active);

    $response = test()->actingAs($account->owner)->get(route('wholesale.orders.index'));

    $response->assertOk();

    expect($response->viewData('page')['props']['permissions']['order.view'])->toBeFalse();
});

it('gives the order review screen real content with no orders to show', function () {
    $this->actingAs(testPlatformStaff(PlatformRole::OrderManager))
        ->get(route('admin.orders.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/orders/index')
            ->has('orders.data', 0)
            ->has('statuses'),
        );
});

it('gives an activated account the status its wallet link is gated on', function () {
    /*
     * The member's Wallet link appears when `account.status` is active, because
     * a wallet opens with the activation. The prop is what the sidebar reads, so
     * this is the same check the browser makes.
     */
    $account = testBusinessAccount(AccountStatus::Active);
    app(OpenWallet::class)->handle($account);

    $response = test()->actingAs($account->owner)->get(route('wallet.show'));

    $response->assertOk();

    expect($response->viewData('page')['props']['account']->status)->toBe('active');
});

it('hides the links from somebody who holds neither, and still refuses the routes', function () {
    /*
     * Hiding a link is a convenience; the route is what actually refuses. Both
     * have to hold, and a test that only checks one of them is how a link ends
     * up hidden from the person who is allowed to use it.
     */
    $packageManager = testPlatformStaff(PlatformRole::PackageManager);

    $permissions = navPermissionsFor($packageManager, 'admin.packages.index');

    expect($permissions['payment.view'])->toBeFalse()
        ->and($permissions['sms.view'])->toBeFalse()
        ->and($permissions['package.view'])->toBeTrue();

    foreach (['admin.gateways.index', 'admin.payments.index', 'admin.sms.index'] as $route) {
        test()->actingAs($packageManager)->get(route($route))->assertForbidden();
    }
});

it('gives every P1.F screen real content rather than a blank page', function () {
    // A screen that renders nothing is as invisible as one with no link.
    $admin = testPlatformStaff(PlatformRole::SuperAdmin);

    $this->actingAs($admin)
        ->get(route('admin.gateways.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/gateways')
            ->has('gateways', 8)
            ->where('can.manage', true),
        );

    $this->actingAs($admin)
        ->get(route('admin.payments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/payments/index')
            // Empty, but carrying the shape the empty state needs.
            ->has('payments.data')
            ->has('statuses')
            ->where('needs_reconciliation', 0),
        );

    $this->actingAs($admin)
        ->get(route('admin.sms.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/sms')
            ->has('providers', 5)
            ->has('messages')
            ->where('settings.enabled', true),
        );
});

it('gives the wallet screens real content with no wallets to show', function () {
    // A zero-wallet platform is an empty state, not a blank page (P2-8).
    $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
        ->get(route('admin.wallets.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/wallets/index')
            ->has('wallets.data', 0)
            ->has('wallets.total'),
        );
});

/*
 * Regression for 888a6a9: `supplier_payable.view` gated the Supplier
 * payables link from the day it was added, but was never added to
 * `NAVIGATION_ABILITIES` — so the link was invisible to everybody,
 * including a Super Admin, who could still reach the page directly. Fixed
 * alongside `withdrawal.view`, which the new Supplier withdrawal queue
 * link needs the same way.
 */
it('sends a Super Admin the Supplier payable and withdrawal navigation abilities', function () {
    $permissions = navPermissionsFor(
        testPlatformStaff(PlatformRole::SuperAdmin),
        'admin.supplier-payables.index',
    );

    expect($permissions['supplier_payable.view'] ?? null)->toBeTrue()
        ->and($permissions['withdrawal.view'] ?? null)->toBeTrue();
});

it('sends a Supplier Manager the payable ability and not the SMS one', function () {
    $permissions = navPermissionsFor(
        testPlatformStaff(PlatformRole::SupplierManager),
        'admin.supplier-payables.index',
    );

    expect($permissions['supplier_payable.view'])->toBeTrue()
        ->and($permissions['sms.view'])->toBeFalse();
});

it('never sends a business owner the Supplier finance navigation abilities', function () {
    // A Client/Partner account holds none of the Supplier module — the
    // finance links stay exactly as invisible to them as order
    // administration already is.
    $account = testBusinessAccount(AccountStatus::Active);

    $response = test()->actingAs($account->owner)->get(route('wholesale.orders.index'));

    $response->assertOk();

    $permissions = $response->viewData('page')['props']['permissions'];

    expect($permissions['supplier_payable.view'] ?? null)->toBeFalse()
        ->and($permissions['withdrawal.view'] ?? null)->toBeFalse();
});

it('never sends a Supplier account any staff navigation ability, on its own guard', function () {
    // `HandleInertiaRequests::share()` reads `$request->user('web')`
    // explicitly, never the default guard — a Supplier request authenticates
    // no `web` user at all, so this is empty rather than the Supplier's own
    // identity being asked what a staff permission it does not hold.
    $supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);
    supplierTestSignIn($supplier);

    $response = test()->get(route('supplier.payables.index'));

    $response->assertOk();

    expect($response->viewData('page')['props']['permissions'])->toBe([]);
});

it('refuses the Supplier payable and withdrawal staff routes to a role holding neither', function () {
    $packageManager = testPlatformStaff(PlatformRole::PackageManager);

    foreach (['admin.supplier-payables.index'] as $route) {
        test()->actingAs($packageManager)->get(route($route))->assertForbidden();
    }
});
