<?php

use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * A connected storefront sells what the partner bought — the products in
 * "My products" — and nothing else, whatever else is published on the website.
 */
beforeEach(function () {
    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create();

    [$this->credential, $this->secret] = storefrontCredential($this->website, [
        CredentialScope::CatalogRead, CredentialScope::InventoryRead, CredentialScope::OrdersWrite,
    ]);
});

/**
 * A published selection of a fresh product on the test website.
 */
function purchasedOnlySelection(Website $website): WebsiteProduct
{
    return WebsiteProduct::create([
        'website_id' => $website->id,
        'business_account_id' => $website->business_account_id,
        'product_id' => websiteTestProduct()->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00', Currency::BDT),
        'published_at' => now(),
    ]);
}

it('lists and serves only the products the partner bought', function () {
    $bought = purchasedOnlySelection($this->website);
    $notBought = purchasedOnlySelection($this->website);
    websiteTestPurchase($this->account, $bought->product);

    storefrontCall($this->credential, $this->secret, 'products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $bought->product->public_id);

    storefrontCall($this->credential, $this->secret, 'products/'.$bought->product->public_id)->assertOk();
    storefrontCall($this->credential, $this->secret, 'products/'.$notBought->product->public_id)->assertNotFound();
});

it('stops listing a product when its purchase is unpaid or cancelled', function () {
    $unpaid = purchasedOnlySelection($this->website);
    $cancelled = purchasedOnlySelection($this->website);
    websiteTestPurchase($this->account, $unpaid->product, ['paid_at' => null]);
    websiteTestPurchase($this->account, $cancelled->product, ['cancelled_at' => now()]);

    storefrontCall($this->credential, $this->secret, 'products')->assertOk()->assertJsonCount(0, 'data');
});

it('refuses to quote a product the partner has not bought', function () {
    $notBought = purchasedOnlySelection($this->website);

    storefrontCall($this->credential, $this->secret, 'checkout/quote', method: 'POST', body: (string) json_encode([
        'items' => [['sku' => $notBought->product->sku, 'quantity' => 1]],
        'payment_method' => 'online',
    ]), overrides: ['headers' => ['Content-Type' => 'application/json']])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'product_unavailable');
});
