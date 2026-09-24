<?php

use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCategory;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Route;

/**
 * **One website's credentials never reach another website's or another user's
 * data** (§17.3, contract §3.5, P5-29).
 *
 * Two partners, two storefronts, two credentials, and every way a storefront
 * could try to read the other's catalogue, categories or stock. Each answer is
 * a `404` — never a `403` — so the API cannot even confirm that the other
 * shop's product exists.
 *
 * The same product sold by both shops is the case that matters most: the
 * product is shared, the selection is not, and each credential sees only its
 * own price.
 */
beforeEach(function () {
    $features = [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ];

    $this->mine = Website::factory()->forAccount(websiteTestAccount(extra: $features))->active()->create();
    $this->theirs = Website::factory()->forAccount(websiteTestAccount(extra: $features))->active()->create();

    [$this->credential, $this->secret] = storefrontCredential($this->mine);

    $this->theirProduct = websiteTestProduct();

    $this->theirSelection = WebsiteProduct::create([
        'website_id' => $this->theirs->id,
        'business_account_id' => $this->theirs->business_account_id,
        'product_id' => $this->theirProduct->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Synced,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('3900.00', Currency::BDT),
        'published_at' => now(),
    ]);

    $this->theirCategory = WebsiteCategory::create([
        'website_id' => $this->theirs->id,
        'name' => 'Their category',
        'slug' => 'their-category',
        'position' => 1,
    ]);
});

it('does not list another website\'s products', function () {
    storefrontCall($this->credential, $this->secret, 'products')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('answers another website\'s product as not found, by identifier or by slug', function () {
    foreach ([$this->theirProduct->public_id, $this->theirProduct->slug] as $identifier) {
        storefrontCall($this->credential, $this->secret, 'products/'.$identifier)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }
});

it('shows each shop only its own price for a product both of them sell', function () {
    WebsiteProduct::create([
        'website_id' => $this->mine->id,
        'business_account_id' => $this->mine->business_account_id,
        'product_id' => $this->theirProduct->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Synced,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2500.00', Currency::BDT),
        'published_at' => now(),
    ]);

    $body = storefrontCall($this->credential, $this->secret, 'products/'.$this->theirProduct->public_id)
        ->assertOk()
        // Both the flat-Taka `amount` and the frozen contract's legacy
        // `minor_units` compatibility key are carried (§4.1).
        ->assertJsonPath('variants.0.price.amount', '2500.00')
        ->assertJsonPath('variants.0.price.minor_units', 250000)
        ->getContent();

    expect($body)->not->toContain('3900.00')
        ->and($body)->not->toContain('390000');
});

it('never carries a Supplier\'s identity or rate through stock or a product page', function () {
    $product = websiteTestProduct();
    $offer = supplierTestOffer(product: $product, supplierRate: '900.00', preferred: true);

    WebsiteProduct::create([
        'website_id' => $this->mine->id,
        'business_account_id' => $this->mine->business_account_id,
        'product_id' => $product->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Synced,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2500.00', Currency::BDT),
        'published_at' => now(),
    ]);

    $productBody = storefrontCall($this->credential, $this->secret, 'products/'.$product->public_id)
        ->assertOk()
        ->assertJsonPath('variants.0.availability.quantity', 10)
        ->getContent();

    $inventoryBody = storefrontCall($this->credential, $this->secret, 'inventory/'.$product->sku)
        ->assertOk()
        ->assertJsonPath('quantity', 10)
        ->getContent();

    foreach ([$productBody, $inventoryBody] as $body) {
        expect(mb_strtolower($body))->not->toContain('supplier')
            ->and($body)->not->toContain($offer->supplier->business_name)
            ->and($body)->not->toContain('900.00');
    }
});

it('answers another website\'s category and stock as not found', function () {
    storefrontCall($this->credential, $this->secret, 'categories/'.$this->theirCategory->public_id)->assertNotFound();
    storefrontCall($this->credential, $this->secret, 'categories/their-category')->assertNotFound();
    storefrontCall($this->credential, $this->secret, 'inventory/'.$this->theirProduct->sku)->assertNotFound();

    storefrontCall($this->credential, $this->secret, 'inventory', ['skus' => [$this->theirProduct->sku]])
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('ignores any website a caller names, because the credential is the website', function () {
    foreach (['website', 'website_id', 'website_public_id', 'account'] as $parameter) {
        storefrontCall($this->credential, $this->secret, 'products', [$parameter => $this->theirs->public_id])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    storefrontCall($this->credential, $this->secret, 'connection', ['website' => $this->theirs->public_id])
        ->assertOk()
        ->assertJsonPath('website.id', $this->mine->public_id);
});

it('has no surface at all for wallets, ledgers, KYC, commissions or accounts', function () {
    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => str_starts_with($uri, 'api/storefront/'));

    expect($uris)->not->toBeEmpty();

    foreach (['wallet', 'ledger', 'kyc', 'commission', 'account', 'user', 'withdrawal', 'referral'] as $forbidden) {
        expect($uris->filter(fn (string $uri) => str_contains($uri, $forbidden))->all())
            ->toBe([], "The storefront API exposes a {$forbidden} route.");
    }
});

it('refuses another website\'s credential presented with this website\'s secret', function () {
    [$theirCredential] = storefrontCredential($this->theirs);

    storefrontCall($theirCredential, $this->secret, 'connection')->assertUnauthorized();
});
