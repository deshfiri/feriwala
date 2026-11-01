<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\Actions\SyncWebsiteCatalogue;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Website\Models\WebsiteProductPriceRule;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * A storefront sells only what its owner selected and published, at a price the
 * owner set inside the administrator's bounds. The whole-catalogue default that
 * used to list everything is retired, and its cleanup migration touches only the
 * rows that default created.
 */
beforeEach(function () {
    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);
    $this->account->forceFill(['account_type' => AccountType::NonConditional])->save();

    $this->website = Website::factory()->forAccount($this->account)->active()->create();
    $this->api = storefrontCredential($this->website, [CredentialScope::CatalogRead, CredentialScope::InventoryRead]);
    $this->product = websiteTestProduct();
});

/**
 * @return array<int, object>
 */
function retiredCatalogueRows(string $table): array
{
    return DB::table($table)->orderBy('id')->get()->all();
}

function retiredCatalogueMigration(): void
{
    (require base_path('database/migrations/2026_11_19_100000_retire_whole_catalogue_listing.php'))->up();
}

function retiredCatalogueRow(Website $website, string $status, bool $autoListed, string $sync, string $price = '2500.00'): WebsiteProduct
{
    return WebsiteProduct::create([
        'website_id' => $website->id,
        'business_account_id' => $website->business_account_id,
        'product_id' => websiteTestProduct()->id,
        'status' => $status,
        'sync_status' => $sync,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal($price, Currency::BDT),
        'auto_listed' => $autoListed,
        'published_at' => $status === WebsiteProductStatus::Published->value ? now() : null,
    ]);
}

describe('nothing is listed on its own', function () {
    it('starts a Non-Conditional partner\'s new storefront with no published product', function () {
        websiteTestOwns($this->account, $this->product, 20);

        $fresh = Website::factory()->forAccount($this->account)->active()->create();

        expect(WebsiteProduct::query()->count())->toBe(0);
        storefrontCall($this->api[0], $this->api[1], 'products')->assertOk()->assertJsonCount(0, 'data');
        expect(WebsiteProduct::query()->where('website_id', $fresh->id)->count())->toBe(0);
    });

    it('publishes nothing when an account is changed to Non-Conditional', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        $conditional = websiteTestAccount(extra: [
            PackageFeature::DropshippingEnabled->value => '1',
            PackageFeature::ProductPublishLimit->value => null,
        ]);
        $website = Website::factory()->forAccount($conditional)->active()->create();

        $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
            ->post(route('admin.accounts.account-type.update', $conditional), [
                'account_type' => AccountType::NonConditional->value,
                'reason' => 'Test',
            ])->assertSessionHasNoErrors();

        expect($conditional->fresh()->account_type)->toBe(AccountType::NonConditional)
            ->and(WebsiteProduct::query()->where('website_id', $website->id)->count())->toBe(0);
    });

    it('publishes nothing when the scheduler or the catalogue sync runs', function () {
        websiteTestOwns($this->account, $this->product, 20);
        app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://shop.example.com/feriwala/webhooks');
        Http::fake();

        $names = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->description)->implode(' | ');

        expect($names)->not->toContain('whole eligible catalogue')
            ->and(fn () => $this->artisan('website:list-catalogue'))->toThrow(CommandNotFoundException::class);

        app(SyncWebsiteCatalogue::class)->sweep();

        expect(WebsiteProduct::query()->count())->toBe(0);
    });

    it('keeps a selected but unpublished product off the storefront', function () {
        websiteTestOwns($this->account, $this->product, 20);

        $this->actingAs($this->account->owner)
            ->post(route('websites.products.store', $this->website->public_id), ['product' => $this->product->public_id])
            ->assertRedirect();

        expect(WebsiteProduct::query()->sole()->status)->toBe(WebsiteProductStatus::Selected);

        storefrontCall($this->api[0], $this->api[1], 'products')->assertOk()->assertJsonCount(0, 'data');
        storefrontCall($this->api[0], $this->api[1], 'products/'.$this->product->public_id)->assertNotFound();
    });

    it('lets a Non-Conditional partner select, price and publish a product, and refuses a price outside the range', function () {
        websiteTestOwns($this->account, $this->product, 20);

        $this->actingAs($this->account->owner)
            ->post(route('websites.products.store', $this->website->public_id), ['product' => $this->product->public_id])
            ->assertRedirect();

        $put = fn (string $price) => $this->actingAs($this->account->owner)
            ->put(route('catalog.mine.websites.update', [$this->product->slug, $this->website->public_id]), ['published' => true, 'price' => $price]);

        // The product's range is ৳2,000 to ৳4,000.
        $put('1999.99')->assertSessionHasErrors('website');
        $put('4000.01')->assertSessionHasErrors('website');
        expect(WebsiteProduct::query()->sole()->status)->toBe(WebsiteProductStatus::Selected);

        $put('3100.00')->assertSessionHasNoErrors();

        storefrontCall($this->api[0], $this->api[1], 'products')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->product->public_id);
        expect(WebsiteProduct::query()->sole()->price?->toDecimal())->toBe('3100.00');
    });

    it('tells the storefront when a product is unpublished, and stops listing it', function () {
        websiteTestOwns($this->account, $this->product, 20);
        app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://shop.example.com/feriwala/webhooks');
        Http::fake(['*' => Http::response('ok', 200)]);

        $put = fn (bool $published) => $this->actingAs($this->account->owner)
            ->put(route('catalog.mine.websites.update', [$this->product->slug, $this->website->public_id]), ['published' => $published, 'price' => '2600.00']);

        $put(true)->assertSessionHasNoErrors();
        $put(false)->assertSessionHasNoErrors();

        storefrontCall($this->api[0], $this->api[1], 'products')->assertOk()->assertJsonCount(0, 'data');

        $selection = WebsiteProduct::query()->sole();

        expect(WebhookDelivery::query()->where('subject_id', $selection->id)->where('event_type', WebhookEvent::ProductUnpublished)->exists())->toBeTrue()
            ->and($selection->sync_status)->toBe(WebsiteSyncStatus::Synced);
    });
});

describe('the cleanup migration', function () {
    beforeEach(function () {
        $this->second = Website::factory()->forAccount($this->account)->active()->create();
        $this->theirs = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1']);
        $this->third = Website::factory()->forAccount($this->theirs)->active()->create();

        $synced = WebsiteSyncStatus::Synced->value;
        $published = WebsiteProductStatus::Published->value;

        $this->autoA = retiredCatalogueRow($this->website, $published, true, $synced);
        $this->autoB = retiredCatalogueRow($this->second, $published, true, $synced);
        $this->autoOff = retiredCatalogueRow($this->second, WebsiteProductStatus::Unpublished->value, true, $synced);
        $this->chosenLive = retiredCatalogueRow($this->second, $published, false, $synced, '3333.00');
        $this->chosenSelected = retiredCatalogueRow($this->second, WebsiteProductStatus::Selected->value, false, WebsiteSyncStatus::Pending->value, '2600.00');
        $this->chosenOff = retiredCatalogueRow($this->website, WebsiteProductStatus::Unpublished->value, false, $synced, '2700.00');
        $this->otherPartner = retiredCatalogueRow($this->third, $published, false, $synced, '3900.00');

        WebsiteProductPriceRule::create([
            'product_id' => $this->product->id,
            'allows_user_pricing' => true,
            'currency_code' => 'BDT',
            'min_price' => Money::fromDecimal('2100.00', Currency::BDT),
            'max_price' => Money::fromDecimal('3900.00', Currency::BDT),
            'effective_from' => now()->subDay(),
        ]);

        websiteTestOwns($this->account, $this->product, 20);

        $this->untouchedTables = ['website_product_price_rules', 'orders', 'order_items', 'partner_stock_items', 'stock_items'];
        $this->before = collect($this->untouchedTables)->mapWithKeys(fn (string $table) => [$table => retiredCatalogueRows($table)])->all();
        $this->chosenBefore = collect([$this->chosenLive, $this->chosenSelected, $this->chosenOff, $this->otherPartner, $this->autoOff])
            ->mapWithKeys(fn (WebsiteProduct $row) => [$row->id => (array) DB::table('website_products')->find($row->id)])->all();
    });

    it('unpublishes only the rows the default listed, and marks each for the storefront\'s next sync', function () {
        retiredCatalogueMigration();

        foreach ([$this->autoA, $this->autoB] as $row) {
            $row->refresh();

            expect($row->status)->toBe(WebsiteProductStatus::Unpublished)
                ->and($row->sync_status)->toBe(WebsiteSyncStatus::Pending)
                ->and($row->unpublished_at)->not->toBeNull()
                ->and($row->auto_listed)->toBeFalse();
        }

        expect(WebsiteProduct::query()->where('status', WebsiteProductStatus::Published)->pluck('id')->sort()->values()->all())
            ->toBe(collect([$this->chosenLive, $this->otherPartner])->pluck('id')->sort()->values()->all());
    });

    it('leaves partner-selected rows and prices, rules, orders and stock exactly as they were', function () {
        retiredCatalogueMigration();

        foreach ($this->chosenBefore as $id => $before) {
            $after = (array) DB::table('website_products')->find($id);

            // The already-unpublished default row only loses its legacy flag.
            $expected = $id === $this->autoOff->id ? [...$before, 'auto_listed' => false] : $before;

            expect($after)->toEqual($expected);
        }

        foreach ($this->untouchedTables as $table) {
            expect(retiredCatalogueRows($table))->toEqual($this->before[$table]);
        }

        expect($this->chosenLive->refresh()->price?->toDecimal())->toBe('3333.00')
            ->and($this->chosenSelected->refresh()->price?->toDecimal())->toBe('2600.00');
    });

    it('has no further effect when run again', function () {
        retiredCatalogueMigration();

        // An owner deliberately re-publishes one of the retired rows in between.
        $this->autoA->refresh()->forceFill(['status' => WebsiteProductStatus::Published, 'sync_status' => WebsiteSyncStatus::Synced])->save();
        $afterFirst = retiredCatalogueRows('website_products');

        retiredCatalogueMigration();

        expect(retiredCatalogueRows('website_products'))->toEqual($afterFirst)
            ->and($this->autoA->refresh()->status)->toBe(WebsiteProductStatus::Published);
    });

    it('is announced to the storefront by the next sync, then reads as synchronised', function () {
        app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://shop.example.com/feriwala/webhooks');
        app(ManageWebhookEndpoint::class)->configure($this->second, $this->account->owner, 'https://shop2.example.com/feriwala/webhooks');
        Http::fake(['*' => Http::response('ok', 200)]);

        retiredCatalogueMigration();

        expect(app(SyncWebsiteCatalogue::class)->sweep())->toBeGreaterThanOrEqual(2);

        foreach ([$this->autoA, $this->autoB] as $row) {
            expect(WebhookDelivery::query()->where('subject_id', $row->id)->where('event_type', WebhookEvent::ProductUnpublished)->exists())->toBeTrue()
                ->and($row->refresh()->sync_status)->toBe(WebsiteSyncStatus::Synced);
        }

        // Nothing left to tell, and the partner's own live product was not announced.
        expect(app(SyncWebsiteCatalogue::class)->sweep())->toBe(0)
            ->and(WebhookDelivery::query()->where('subject_id', $this->otherPartner->id)->exists())->toBeFalse();
    });
});

it('no longer offers whole-catalogue listing anywhere', function () {
    $root = base_path();
    $pattern = '/whole[_-]?catalogue|WholeCatalogue|catalogue[_-]mode|catalogueMode|list-catalogue/';

    // The two retained legacy columns may be named in their models' docblocks
    // and casts, and nowhere else outside migrations.
    $allowed = ['app/Domain/Website/Models/Website.php', 'app/Domain/Website/Models/WebsiteProduct.php'];
    $found = [];

    foreach (['app', 'routes', 'lang', 'resources/js/pages', 'resources/js/components', 'resources/js/types', 'database/factories', 'database/seeders'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (! preg_match('/\.(php|tsx?)$/', $relative) || in_array($relative, $allowed, true)) {
                continue;
            }

            $text = (string) file_get_contents($file->getPathname());

            if (preg_match($pattern, $text) || str_contains($text, 'auto_listed')) {
                $found[] = $relative;
            }
        }
    }

    expect($found)->toBe([]);
});
