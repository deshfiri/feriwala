<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Enums\WebsiteTheme;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCategory;
use App\Domain\Website\Models\WebsiteCustomer;
use App\Domain\Website\Models\WebsiteProduct;
use App\Models\User;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Platform staff standing in for a partner on that partner's own storefront.
 *
 * An administrator who stands a website up for a partner can change what the
 * partner could change — settings, products, categories — and see its orders.
 * It is the partner's own controllers behind `admin.*` routes, so the rules are
 * the partner's rules; these assert the doors, not the rules behind them.
 * Spending the partner's wallet is deliberately not among the doors.
 */
/**
 * An order the website took, with everything the database requires of one: its
 * customer, its payment and the storefront's own reference.
 */
function adminWebsiteTestOrder(Website $website): Order
{
    $payment = Payment::create([
        'business_account_id' => $website->business_account_id,
        'purpose' => PaymentPurpose::WebsiteOrder,
        'status' => PaymentStatus::Pending,
        'gateway' => null,
        'currency_code' => 'BDT',
        'amount' => Money::fromDecimal('1000.00'),
    ]);

    $buyer = WebsiteCustomer::create([
        'website_id' => $website->id,
        'mobile' => '+88017'.random_int(10000000, 99999999),
        'name' => 'Ayesha Rahman',
    ]);

    return Order::factory()->create([
        'source' => OrderSource::Website,
        'website_id' => $website->id,
        'website_customer_id' => $buyer->id,
        'storefront_order_reference' => 'SF-'.Str::upper(Str::random(10)),
        'placed_by' => null,
        'cart_id' => null,
        'business_account_id' => $website->business_account_id,
        'payment_id' => $payment->id,
        'status' => OrderStatus::CustomerVerificationPending,
        'placed_at' => now()->subDay(),
    ]);
}

beforeEach(function () {
    Storage::fake('public');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = websiteTestAccount(extra: [
        PackageFeature::ProductPublishLimit->value => '5',
        PackageFeature::DropshippingEnabled->value => '1',
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create();
    $this->product = websiteTestProduct(['name' => 'Cotton panjabi']);
    websiteTestPurchase($this->account, $this->product);

    $this->admin = testPlatformStaff(PlatformRole::Admin);
});

describe('storefront settings', function () {
    it('opens on the admin side and tells the page so', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.websites.settings.edit', $this->website->public_id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('websites/settings')
                ->where('scope', 'admin')
                ->where('website.id', $this->website->public_id));
    });

    it('saves a partner\'s name and branding, and lands back on the admin page', function () {
        $this->actingAs($this->admin)
            ->put(route('admin.websites.settings.update', $this->website->public_id), [
                'name' => 'Nasrin Fashion House',
                'tagline' => 'Everyday cotton, delivered',
                'theme' => WebsiteTheme::Modern->value,
                'primary_color' => '#0b0b0b',
                'secondary_color' => '#ff6600',
            ])
            ->assertRedirect(route('admin.websites.settings.edit', $this->website->public_id));

        expect($this->website->refresh()->name)->toBe('Nasrin Fashion House');
    });

    it('holds an administrator to the same validation as the partner', function () {
        $this->actingAs($this->admin)
            ->from(route('admin.websites.settings.edit', $this->website->public_id))
            ->put(route('admin.websites.settings.update', $this->website->public_id), [
                'name' => 'Nasrin Fashion',
                'theme' => WebsiteTheme::Classic->value,
                'primary_color' => 'orange',
                'secondary_color' => '#ff6600',
            ])
            ->assertSessionHasErrors('primary_color');
    });

    it('turns the SMS confirmation on and off for the partner', function () {
        $this->actingAs($this->admin)
            ->put(route('admin.websites.settings.sms-verification.update', $this->website->public_id), ['enabled' => 0])
            ->assertRedirect(route('admin.websites.settings.edit', $this->website->public_id));

        expect($this->website->refresh()->sms_verification_enabled)->toBeFalse();
    });

    it('leaves the partner\'s own page on the partner\'s side', function () {
        $this->actingAs($this->account->owner)
            ->get(route('websites.settings.edit', $this->website->public_id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('scope', 'partner'));
    });
});

describe('products', function () {
    it('finds only what the partner could have chosen, and marks what is already sold', function () {
        $unbought = websiteTestProduct(['name' => 'Cotton lungi']);

        $this->actingAs($this->admin)
            ->get(route('admin.websites.products.index', [$this->website->public_id, 'find' => 'Cotton']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'admin')
                ->where('find', 'Cotton')
                ->has('candidates', 1)
                ->where('candidates.0.id', $this->product->public_id)
                ->where('candidates.0.selected', false));

        expect($unbought->public_id)->not->toBe($this->product->public_id);
    });

    it('lists nothing for a search too short to mean anything', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.websites.products.index', [$this->website->public_id, 'find' => 'C']))
            ->assertInertia(fn ($page) => $page->has('candidates', 0));
    });

    it('adds a product to the partner\'s storefront, edits the selection and removes it', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.websites.products.store', $this->website->public_id), ['product' => $this->product->public_id])
            ->assertSessionHasNoErrors();

        $selection = WebsiteProduct::query()->where('website_id', $this->website->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.websites.products.index', [$this->website->public_id, 'find' => 'Cotton']))
            ->assertInertia(fn ($page) => $page->where('candidates.0.selected', true));

        $this->actingAs($this->admin)
            ->patch(route('admin.websites.products.update', [$this->website->public_id, $selection->public_id]), [
                'display_order' => 7,
                'is_featured' => true,
            ])
            ->assertSessionHasNoErrors();

        expect($selection->refresh()->display_order)->toBe(7)
            ->and($selection->is_featured)->toBeTrue();

        $this->actingAs($this->admin)
            ->delete(route('admin.websites.products.destroy', [$this->website->public_id, $selection->public_id]))
            ->assertSessionHasNoErrors();

        expect(WebsiteProduct::query()->where('website_id', $this->website->id)->count())->toBe(0);
    });

    it('refuses a product the partner has not bought, as it would the partner', function () {
        $unbought = websiteTestProduct();

        $this->actingAs($this->admin)
            ->from(route('admin.websites.products.index', $this->website->public_id))
            ->post(route('admin.websites.products.store', $this->website->public_id), ['product' => $unbought->public_id])
            ->assertSessionHasErrors(['product' => __('website.refused.product_not_purchased')]);

        expect(WebsiteProduct::query()->count())->toBe(0);
    });
});

describe('categories', function () {
    it('adds, renames and removes a category on the partner\'s behalf', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.websites.categories.store', $this->website->public_id), ['name' => 'Summer'])
            ->assertSessionHasNoErrors();

        $category = WebsiteCategory::query()->where('website_id', $this->website->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->patch(route('admin.websites.categories.update', [$this->website->public_id, $category->public_id]), ['name' => 'Winter'])
            ->assertSessionHasNoErrors();

        expect($category->refresh()->name)->toBe('Winter');

        $this->actingAs($this->admin)
            ->delete(route('admin.websites.categories.destroy', [$this->website->public_id, $category->public_id]))
            ->assertSessionHasNoErrors();

        expect(WebsiteCategory::query()->where('website_id', $this->website->id)->count())->toBe(0);
    });
});

describe('orders', function () {
    it('opens the platform order list filtered to this one website', function () {
        $other = Website::factory()->forAccount(websiteTestAccount())->active()->create();

        $mine = adminWebsiteTestOrder($this->website);
        adminWebsiteTestOrder($other);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['website' => $this->website->public_id, 'source' => 'website']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $mine->public_id)
                ->where('filters.website', $this->website->public_id));
    });

    it('offers the link only to staff who may see orders', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.websites.show', $this->website->public_id))
            ->assertInertia(fn ($page) => $page
                ->where('can.administer', true)
                ->where('can.view_orders', true));

        $viewer = User::factory()->staff()->create();
        $viewer->givePermissionTo('website.view');

        $this->actingAs($viewer)
            ->get(route('admin.websites.show', $this->website->public_id))
            ->assertInertia(fn ($page) => $page
                ->where('can.administer', false)
                ->where('can.view_orders', false));
    });
});

describe('who gets in', function () {
    it('refuses a partner on every admin storefront route, even their own website', function () {
        $owner = $this->account->owner;
        $id = $this->website->public_id;

        $this->actingAs($owner)->get(route('admin.websites.settings.edit', $id))->assertForbidden();
        $this->actingAs($owner)->get(route('admin.websites.products.index', $id))->assertForbidden();
        $this->actingAs($owner)->get(route('admin.websites.categories.index', $id))->assertForbidden();
        $this->actingAs($owner)->put(route('admin.websites.settings.update', $id), ['name' => 'Hijacked'])->assertForbidden();

        expect($this->website->refresh()->name)->not->toBe('Hijacked');
    });

    it('refuses staff who can look at a website but not administer it', function () {
        $viewer = User::factory()->staff()->create();
        $viewer->givePermissionTo('website.view');
        $id = $this->website->public_id;

        $this->actingAs($viewer)->get(route('admin.websites.settings.edit', $id))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.websites.products.index', $id))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.websites.categories.index', $id))->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('admin.websites.categories.store', $id), ['name' => 'Summer'])
            ->assertForbidden();

        expect(WebsiteCategory::query()->count())->toBe(0);
    });

    it('answers 404 for a website that does not exist rather than confirming anything', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.websites.settings.edit', '01HZZZZZZZZZZZZZZZZZZZZZZZ'))
            ->assertNotFound();
    });

    it('still keeps the partner\'s wallet out of reach: paying is the partner\'s own act', function () {
        expect(Gate::forUser($this->admin)->denies('pay', $this->website))->toBeTrue()
            ->and(Gate::forUser($this->account->owner)->allows('pay', $this->website))->toBeTrue();
    });
});
