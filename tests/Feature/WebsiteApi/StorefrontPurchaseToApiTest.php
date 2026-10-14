<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCredential;
use App\Domain\Website\Models\WebsiteProduct;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The whole path a product takes to a partner's own storefront (D32, Option P1):
 * an authorised operator issues the website's credential, the owner buys the
 * product wholesale, finds it under My Products, connects and publishes it, and
 * the storefront reads it through the signed API with the values the operator
 * copied — nothing in the storefront changes.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $features = [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ];

    $this->account = websiteTestAccount(extra: $features);
    $this->website = Website::factory()->forAccount($this->account)->active()->create();
    $this->product = websiteTestProduct();
});

/**
 * Issue a credential the way the operator does, and read back what the
 * operator would copy into the storefront's server-side environment.
 *
 * @return array{0: WebsiteCredential, 1: string, 2: array<string, string>}
 */
function purchaseToApiIssue(Website $website): array
{
    $response = test()->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
        ->postJson(route('admin.websites.credentials.store', $website->public_id), [
            'name' => 'Production storefront',
            'scopes' => ['catalog:read', 'inventory:read', 'orders:write', 'orders:read', 'customers:write', 'returns:write'],
        ])
        ->assertCreated();

    $environment = [];

    foreach (preg_split('/\R/', (string) $response->json('env')) as $line) {
        if (preg_match('/^(BANIJ_[A-Z_]+)=(.*)$/', $line, $match) === 1) {
            $environment[$match[1]] = $match[2];
        }
    }

    $credential = WebsiteCredential::query()->where('key_id', $environment['BANIJ_CREDENTIAL_KEY_ID'] ?? '')->firstOrFail();

    return [$credential, $environment['BANIJ_CREDENTIAL_SECRET'] ?? '', $environment];
}

it('takes a paid wholesale purchase to the right storefront through the signed API', function () {
    [$credential, $secret, $environment] = purchaseToApiIssue($this->website);

    // B: the three values the operator copies, exactly as the storefront reads them.
    expect(array_keys($environment))->toBe(['BANIJ_ERP_BASE_URL', 'BANIJ_CREDENTIAL_KEY_ID', 'BANIJ_CREDENTIAL_SECRET'])
        ->and($environment['BANIJ_CREDENTIAL_KEY_ID'])->toMatch('/^wsk_[0-9A-Z]{26}$/')
        ->and($environment['BANIJ_CREDENTIAL_SECRET'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($credential->website_id)->toBe($this->website->id);

    // Before the purchase there is nothing to sell.
    storefrontCall($credential, $secret, 'products')->assertOk()->assertJsonCount(0, 'data');

    // C: a paid, uncancelled wholesale purchase appears under My Products.
    websiteTestPurchase($this->account, $this->product);

    $this->actingAs($this->account->owner)->get(route('catalog.index', ['scope' => 'mine']))
        ->assertInertia(fn ($page) => $page->has('products.data', 1)->where('products.data.0.slug', $this->product->slug));

    // D: the owner connects it to their own website and publishes it.
    $this->actingAs($this->account->owner)
        ->post(route('websites.products.store', $this->website->public_id), ['product' => $this->product->public_id])
        ->assertRedirect();

    $selection = WebsiteProduct::query()->where('website_id', $this->website->id)->where('product_id', $this->product->id)->firstOrFail();

    $this->actingAs($this->account->owner)
        ->put(route('websites.products.publication.update', [$this->website->public_id, $selection->public_id]), ['published' => true])
        ->assertRedirect();

    // E: the storefront sees it, with only the copied key id and secret.
    storefrontCall($credential, $secret, 'products')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->product->public_id);

    storefrontCall($credential, $secret, 'products/'.$this->product->public_id)->assertOk();
});

it('keeps one website\'s product and credential away from another website', function () {
    [$ours, $ourSecret] = purchaseToApiIssue($this->website);

    $otherAccount = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1', PackageFeature::ProductPublishLimit->value => null]);
    $theirs = Website::factory()->forAccount($otherAccount)->active()->create();
    [$theirCredential, $theirSecret] = purchaseToApiIssue($theirs);

    websiteTestPurchase($otherAccount, $this->product);

    $this->actingAs($otherAccount->owner)
        ->post(route('websites.products.store', $theirs->public_id), ['product' => $this->product->public_id])
        ->assertRedirect();

    $selection = WebsiteProduct::query()->where('website_id', $theirs->id)->firstOrFail();

    $this->actingAs($otherAccount->owner)
        ->put(route('websites.products.publication.update', [$theirs->public_id, $selection->public_id]), ['published' => true])
        ->assertRedirect();

    storefrontCall($theirCredential, $theirSecret, 'products')->assertOk()->assertJsonCount(1, 'data');

    // H: our credential reads our website only, and our secret cannot sign for theirs.
    storefrontCall($ours, $ourSecret, 'products')->assertOk()->assertJsonCount(0, 'data');
    storefrontCall($ours, $ourSecret, 'products/'.$this->product->public_id)->assertNotFound();
    storefrontCall($theirCredential, $ourSecret, 'products')->assertUnauthorized();

    // F: our owner cannot connect a product only the other account bought.
    $this->actingAs($this->account->owner)
        ->from(route('websites.products.index', $this->website->public_id))
        ->post(route('websites.products.store', $this->website->public_id), ['product' => $this->product->public_id])
        ->assertSessionHasErrors(['product' => __('website.refused.product_not_purchased')]);
});
