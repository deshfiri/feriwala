<?php

use App\Domain\Account\Actions\ChangeAccountType;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Actions\ListWholeCatalogueOnWebsite;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Testing\TestResponse;

/**
 * A Non-Conditional account's storefront lists the whole eligible catalogue by
 * default, at each product's suggested price; the owner may price a product,
 * hide one, or narrow the shop to chosen products. A Conditional account's shop
 * is never listed this way.
 */
beforeEach(function () {
    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create();
    $this->api = storefrontCredential($this->website, [CredentialScope::CatalogRead, CredentialScope::InventoryRead]);
    $this->product = websiteTestProduct();
});

function wholeCatalogueNonConditional(): void
{
    test()->account->forceFill(['account_type' => AccountType::NonConditional])->save();
}

function wholeCatalogueSweep(): int
{
    return app(ListWholeCatalogueOnWebsite::class)->sweep();
}

function wholeCatalogueListing(): TestResponse
{
    return storefrontCall(test()->api[0], test()->api[1], 'products');
}

function wholeCatalogueSetMode(bool $whole): TestResponse
{
    return test()->actingAs(test()->account->owner)
        ->put(route('websites.settings.catalogue-mode.update', test()->website->public_id), ['whole_catalogue' => $whole]);
}

function wholeCatalogueOwnerPut(array $data): TestResponse
{
    return test()->actingAs(test()->account->owner)
        ->put(route('catalog.mine.websites.update', [test()->product->slug, test()->website->public_id]), $data);
}

describe('the default listing', function () {
    it('lists every eligible product at its suggested price without anything being chosen', function () {
        wholeCatalogueNonConditional();

        expect(wholeCatalogueSweep())->toBe(1);

        $row = WebsiteProduct::query()->sole();

        expect($row->status)->toBe(WebsiteProductStatus::Published)
            ->and($row->auto_listed)->toBeTrue()
            ->and($row->price?->toDecimal())->toBe('2500.00');

        wholeCatalogueListing()->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->product->public_id);
    });

    it('is idempotent and picks up a product added later', function () {
        wholeCatalogueNonConditional();
        wholeCatalogueSweep();

        expect(wholeCatalogueSweep())->toBe(0);

        websiteTestProduct();

        expect(wholeCatalogueSweep())->toBe(1)
            ->and(WebsiteProduct::query()->count())->toBe(2);
    });

    it('lists it as soon as the account is made Non-Conditional, without waiting for the sweep', function () {
        app(ChangeAccountType::class)->handle($this->account->owner, $this->account, AccountType::NonConditional, 'Trusted reseller');
        app()->terminate();

        expect(WebsiteProduct::query()->sole()->auto_listed)->toBeTrue();
        wholeCatalogueListing()->assertOk()->assertJsonCount(1, 'data');
    });

    it('puts on sale a product the owner picked but never published, and keeps it the owner\'s own', function () {
        wholeCatalogueNonConditional();
        WebsiteProduct::create([
            'website_id' => $this->website->id,
            'business_account_id' => $this->account->id,
            'product_id' => $this->product->id,
            'status' => WebsiteProductStatus::Selected,
            'currency_code' => $this->website->currency_code,
            'price' => Money::fromDecimal('1250.00', Currency::BDT),
        ]);

        wholeCatalogueSweep();

        $row = WebsiteProduct::query()->sole();

        expect($row->status)->toBe(WebsiteProductStatus::Published)
            ->and($row->auto_listed)->toBeFalse()
            ->and($row->price?->toDecimal())->toBe('1250.00');

        wholeCatalogueListing()->assertOk()->assertJsonCount(1, 'data');
    });

    it('leaves a Conditional account\'s shop alone', function () {
        expect(wholeCatalogueSweep())->toBe(0)
            ->and(WebsiteProduct::query()->count())->toBe(0);
    });

    it('does not list a product with no suggested price', function () {
        wholeCatalogueNonConditional();
        $this->product->forceFill(['suggested_selling_price' => null])->save();

        expect(wholeCatalogueSweep())->toBe(0);
    });

    it('does not count against the package publish limit', function () {
        $limited = websiteTestAccount(extra: [
            PackageFeature::DropshippingEnabled->value => '1',
            PackageFeature::ProductPublishLimit->value => '1',
        ]);
        $limited->forceFill(['account_type' => AccountType::NonConditional])->save();
        Website::factory()->forAccount($limited)->active()->create();
        websiteTestProduct();

        wholeCatalogueSweep();

        // Two products listed for the account that may publish one.
        expect(WebsiteProduct::query()->where('business_account_id', $limited->id)->count())->toBe(2);
    });
});

describe('what the owner decides', function () {
    it('makes a product the owner\'s own by pricing it, inside the bounds', function () {
        wholeCatalogueNonConditional();
        wholeCatalogueSweep();

        wholeCatalogueOwnerPut(['published' => true, 'price' => '3100.00'])->assertSessionHasNoErrors();

        $row = WebsiteProduct::query()->sole();

        expect($row->price?->toDecimal())->toBe('3100.00')
            ->and($row->auto_listed)->toBeFalse()
            ->and($row->status)->toBe(WebsiteProductStatus::Published);

        wholeCatalogueOwnerPut(['published' => true, 'price' => '1999.00'])->assertSessionHasErrors('website');
        expect(WebsiteProduct::query()->sole()->price?->toDecimal())->toBe('3100.00');
    });

    it('keeps a product the owner hid off the shop, whatever the sweep does', function () {
        wholeCatalogueNonConditional();
        wholeCatalogueSweep();

        wholeCatalogueOwnerPut(['published' => false])->assertSessionHasNoErrors();
        wholeCatalogueSweep();

        expect(WebsiteProduct::query()->sole()->status)->toBe(WebsiteProductStatus::Unpublished);
        wholeCatalogueListing()->assertOk()->assertJsonCount(0, 'data');
    });

    it('narrows the shop to chosen products and back, keeping what the owner priced', function () {
        wholeCatalogueNonConditional();
        $chosen = $this->product;
        wholeCatalogueSweep();
        wholeCatalogueOwnerPut(['published' => true, 'price' => '3100.00']);
        $other = websiteTestProduct();
        wholeCatalogueSweep();

        wholeCatalogueSetMode(false)->assertSessionHasNoErrors();

        wholeCatalogueListing()->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $chosen->public_id);
        // Narrowed, the sweep adds nothing.
        websiteTestProduct();
        expect(wholeCatalogueSweep())->toBe(0);

        wholeCatalogueSetMode(true)->assertSessionHasNoErrors();

        wholeCatalogueListing()->assertOk()->assertJsonCount(3, 'data');
        expect(WebsiteProduct::query()->where('product_id', $chosen->id)->sole()->price?->toDecimal())->toBe('3100.00')
            ->and(WebsiteProduct::query()->where('product_id', $other->id)->sole()->status)->toBe(WebsiteProductStatus::Published);
    });

    it('refuses the switch for a Conditional account', function () {
        wholeCatalogueSetMode(false)->assertSessionHasErrors('lists_whole_catalogue');

        expect($this->website->refresh()->lists_whole_catalogue)->toBeTrue();
    });

    it('does not let another partner change this shop', function () {
        wholeCatalogueNonConditional();
        $stranger = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1']);

        $this->actingAs($stranger->owner)
            ->put(route('websites.settings.catalogue-mode.update', $this->website->public_id), ['whole_catalogue' => false])
            ->assertNotFound();

        expect($this->website->refresh()->lists_whole_catalogue)->toBeTrue();
    });
});
