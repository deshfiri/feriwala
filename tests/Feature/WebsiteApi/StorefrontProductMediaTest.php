<?php

use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Storage\R2StorageSettings;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Integrations\Storage\R2Manager;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Product images as a storefront receives them (contract §5.1).
 *
 * Each URL comes from the disk the file was written to, and every item carries
 * its size and the listed variation it shows, under `catalog:read` alone.
 */
beforeEach(function () {
    Storage::fake('public', ['url' => 'https://erp.example.test/storage']);

    $account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($account)->active()->create();

    // Catalogue only: the preview credential's shape.
    [$this->credential, $this->secret] = storefrontCredential($this->website, [CredentialScope::CatalogRead]);

    $this->selection = WebsiteProduct::create([
        'website_id' => $this->website->id,
        'business_account_id' => $this->website->business_account_id,
        'product_id' => websiteTestProduct(['name' => 'Cotton Panjabi'])->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00', Currency::BDT),
        'published_at' => now(),
    ]);
    websiteTestOwnsSelection($this->selection, 100);

    $this->product = $this->selection->product;
});

/**
 * @param  array<string, mixed>  $attributes
 */
function storefrontMediaRow(array $attributes): ProductMedia
{
    return ProductMedia::create([
        'product_id' => test()->product->id,
        'type' => ProductMedia::TYPE_IMAGE,
        'disk' => 'public',
        'path' => 'catalog/products/'.test()->product->public_id.'/'.bin2hex(random_bytes(8)).'.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 120_000,
        ...$attributes,
    ]);
}

function storefrontMediaRead(): TestResponse
{
    return storefrontCall(test()->credential, test()->secret, 'products/'.test()->product->public_id)->assertOk();
}

/**
 * R2 switched on, its client answering from a faked disk with its own URL.
 */
function storefrontMediaEnableR2(): void
{
    $settings = app(SettingsRepository::class);

    foreach ([
        [R2StorageSettings::ENABLED, SettingType::Boolean, true, false],
        [R2StorageSettings::ACCOUNT_ID, SettingType::String, 'acct-1', false],
        [R2StorageSettings::ACCESS_KEY_ID, SettingType::String, 'key-1', true],
        [R2StorageSettings::SECRET_ACCESS_KEY, SettingType::String, 'secret-1', true],
        [R2StorageSettings::BUCKET, SettingType::String, 'bucket-1', false],
        [R2StorageSettings::ENDPOINT, SettingType::String, 'https://example.r2.cloudflarestorage.com', false],
    ] as [$key, $type, $value, $encrypted]) {
        $settings->define($key, 'storage', $type, isEncrypted: $encrypted);
        $settings->set($key, $value);
    }

    Storage::fake('r2_test', ['url' => 'https://media.example.test']);

    test()->mock(R2Manager::class, fn ($mock) => $mock
        ->shouldReceive('disk')
        ->andReturnUsing(fn () => Storage::disk('r2_test')));
}

it('serves images from local public storage, primary first, with their size and variation', function () {
    $variant = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'PNJ-M', 'combination_key' => 'm', 'is_active' => true]);

    $gallery = storefrontMediaRow(['position' => 2, 'alt_text' => 'Collar detail', 'width' => 800, 'height' => 1000, 'product_variant_id' => $variant->id]);
    $primary = storefrontMediaRow(['position' => 1, 'alt_text' => 'Front view', 'width' => 1600, 'height' => 2000]);

    storefrontMediaRead()
        ->assertJsonCount(2, 'media')
        ->assertJsonPath('media.0', [
            'url' => 'https://erp.example.test/storage/'.$primary->path,
            'alt' => 'Front view',
            'position' => 1,
            'type' => 'image',
            'width' => 1600,
            'height' => 2000,
            'variant_id' => null,
        ])
        ->assertJsonPath('media.1.url', 'https://erp.example.test/storage/'.$gallery->path)
        ->assertJsonPath('media.1.position', 2)
        ->assertJsonPath('media.1.variant_id', $variant->public_id);
});

it('serves an R2 file from R2, and a file still on local storage from there, while R2 is on', function () {
    storefrontMediaEnableR2();

    $onR2 = storefrontMediaRow(['position' => 1, 'disk' => 'r2']);
    $stillLocal = storefrontMediaRow(['position' => 2, 'disk' => 'public']);

    storefrontMediaRead()
        ->assertJsonPath('media.0.url', 'https://media.example.test/'.$onR2->path)
        ->assertJsonPath('media.1.url', 'https://erp.example.test/storage/'.$stillLocal->path);
});

it('names no variation it does not list, and leaves an unknown size empty', function () {
    $hidden = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'PNJ-XL', 'combination_key' => 'xl', 'is_active' => false]);
    ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'PNJ-L', 'combination_key' => 'l', 'is_active' => true]);

    storefrontMediaRow(['position' => 1, 'product_variant_id' => $hidden->id, 'width' => null, 'height' => null]);

    storefrontMediaRead()
        ->assertJsonPath('media.0.variant_id', null)
        ->assertJsonPath('media.0.width', null)
        ->assertJsonPath('media.0.height', null);
});

it('lists images on the catalogue page too, under catalogue access alone', function () {
    $primary = storefrontMediaRow(['position' => 1, 'width' => 1200, 'height' => 1200]);

    storefrontCall($this->credential, $this->secret, 'products')
        ->assertOk()
        ->assertJsonPath('data.0.media.0.url', 'https://erp.example.test/storage/'.$primary->path)
        ->assertJsonPath('data.0.media.0.width', 1200);
});
