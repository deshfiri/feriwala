<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Http\Requests\Catalog\SaveProductRequest;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The `product-restrictions` group (P3-21, §12, §43).
 *
 * §12 says the restrictions are enforced through UI permissions, backend
 * authorisation, API authorisation, request validation, database rules where
 * appropriate, and automated tests. The rest of this directory holds each layer
 * in depth; this file is the matrix. It walks §12's list of what a regular user
 * must not do and shows every act refused, then takes the one act the section
 * opens with — creating a product — and shows each layer refusing it on its own,
 * so a layer that stopped refusing fails here by name rather than being covered
 * for by the layer in front of it.
 *
 * Run the whole group with `pest --group=product-restrictions`.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->category = Category::create(['name' => 'Kitchen']);
    $this->brand = Brand::create(['name' => 'Walton']);
    $this->product = Product::create([
        'name' => 'Rice cooker',
        'sku' => 'FW-RC',
        'category_id' => $this->category->id,
        'base_cost' => Money::fromDecimal('1800.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('2100.00', Currency::BDT),
    ]);

    /*
     * The strongest regular user there is: an active partner who has somehow
     * been handed every catalogue permission. §12 has to hold for them too.
     */
    $this->partner = tap(
        testBusinessAccount(AccountStatus::Active)->owner,
        fn (User $owner) => $owner->givePermissionTo(['catalog.view', 'catalog.create', 'catalog.edit', 'catalog.delete', 'catalog.publish']),
    );
});

/**
 * A valid product form submission.
 *
 * @return array<string, mixed>
 */
function restrictionLayersProductPayload(array $overrides = []): array
{
    return [
        'name' => 'Imported kettle',
        'sku' => 'EXT-KT',
        'category_id' => Category::query()->value('public_id'),
        'base_cost' => '1000.00',
        'wholesale_price' => '1200.00',
        ...$overrides,
    ];
}

/**
 * Everything §12 says a regular user must not change, as it stands.
 *
 * @return array<string, mixed>
 */
function restrictionLayersState(): array
{
    return [
        'products' => Product::query()->orderBy('id')->get()->map->getAttributes()->all(),
        'variants' => ProductVariant::query()->count(),
        'categories' => Category::query()->count(),
        'brands' => Brand::query()->count(),
    ];
}

describe('every act §12 forbids a regular user is refused, in the browser and to an API caller', function () {
    it('refuses it', function (string $method, Closure $url, Closure $payload) {
        $before = restrictionLayersState();

        $this->actingAs($this->partner)
            ->call($method, $url($this), $payload($this))
            ->assertForbidden();

        $this->actingAs($this->partner)
            ->json($method, $url($this), $payload($this))
            ->assertForbidden()
            ->assertJsonMissingPath('errors');

        expect(restrictionLayersState())->toBe($before);
    })->with([
        'create product master data' => ['POST', fn () => route('admin.catalog.products.store'), fn () => restrictionLayersProductPayload()],
        'add an external product' => ['POST', fn () => route('admin.catalog.products.store'), fn () => restrictionLayersProductPayload(['external_id' => 'SUPPLIER-99'])],
        'upload an unauthorised product' => ['POST', fn () => route('admin.catalog.products.store'), fn () => ['file' => UploadedFile::fake()->createWithContent('products.csv', "sku,name\nEXT-1,Kettle\n")]],
        'create a product category' => ['POST', fn () => route('admin.catalog.categories.store'), fn () => ['name' => 'Garden']],
        'create a product brand' => ['POST', fn () => route('admin.catalog.brands.store'), fn () => ['name' => 'Acme']],
        'create a product variation' => ['POST', fn ($test) => route('admin.catalog.products.variants.store', $test->product->public_id), fn () => ['sku' => 'FW-RC-XL', 'values' => []]],
        'modify the central SKU' => ['PATCH', fn ($test) => route('admin.catalog.products.update', $test->product->public_id), fn () => restrictionLayersProductPayload(['sku' => 'MINE-1'])],
        'modify central stock' => ['PATCH', fn ($test) => route('admin.catalog.products.update', $test->product->public_id), fn () => restrictionLayersProductPayload(['sku' => 'FW-RC', 'stock' => 999])],
        'modify the central wholesale price' => ['PATCH', fn ($test) => route('admin.catalog.products.update', $test->product->public_id), fn () => restrictionLayersProductPayload(['sku' => 'FW-RC', 'wholesale_price' => '0.01'])],
        'modify locked product information' => ['PATCH', fn ($test) => route('admin.catalog.products.status.update', $test->product->public_id), fn () => ['status' => 'active']],
        'import external products' => ['POST', fn ($test) => route('admin.catalog.products.bulk'), fn ($test) => ['products' => [$test->product->public_id], 'action' => 'feature', 'enable' => true]],
    ]);
});

describe('each layer refuses creating a product on its own', function () {
    it('UI: the shared contract grants no catalogue administration, and a typed address shows the refusal page', function () {
        $this->actingAs($this->partner)
            ->get(route('catalog.wholesale.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions', fn ($permissions) => collect($permissions)
                    ->filter(fn (bool $granted, string $name) => str_starts_with($name, 'catalog.') && $granted)
                    ->isEmpty()),
            );

        $this->actingAs($this->partner)
            ->get(route('admin.catalog.products.create'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('catalog/forbidden')->where('audience', 'business'));
    });

    it('policy: every way of asking the question answers no', function () {
        expect(CatalogPolicy::canCreate($this->partner))->toBeFalse()
            ->and($this->partner->can('catalog.create'))->toBeFalse()
            ->and(Gate::forUser($this->partner)->allows('create', Product::class))->toBeFalse();
    });

    it('request: the form request refuses to authorise before a rule runs', function () {
        $request = SaveProductRequest::create(route('admin.catalog.products.store'), 'POST', restrictionLayersProductPayload());
        $request->setUserResolver(fn () => $this->partner);

        expect($request->authorize())->toBeFalse();
    });

    it('action: the action refuses a caller that skipped the controller', function () {
        expect(fn () => app(ManageProducts::class)->create($this->partner, [
            'name' => 'Imported kettle',
            'sku' => 'EXT-KT',
            'category_id' => $this->category->id,
            'base_cost' => '1000.00',
            'wholesale_price' => '1200.00',
        ]))->toThrow(AuthorizationException::class);

        expect(Product::query()->where('sku', 'EXT-KT')->exists())->toBeFalse();
    });

    it('controller and API: a 403 as a page to the browser and as JSON to an API caller', function () {
        $this->actingAs($this->partner)
            ->post(route('admin.catalog.products.store'), restrictionLayersProductPayload())
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('catalog/forbidden'));

        $this->actingAs($this->partner)
            ->postJson(route('admin.catalog.products.store'), restrictionLayersProductPayload())
            ->assertForbidden()
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('errors');

        expect(Product::query()->where('sku', 'EXT-KT')->exists())->toBeFalse();
    });

    it('validation: even an authorised editor cannot create a product that starts live, with stock, or from a file', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))
            ->postJson(route('admin.catalog.products.store'), restrictionLayersProductPayload([
                'status' => 'active',
                'stock' => 50,
                'products' => [['sku' => 'EXT-2']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'stock', 'products']);

        expect(Product::query()->where('sku', 'EXT-KT')->exists())->toBeFalse();
    });

    it('database: a write around every other layer still cannot rewrite what identifies a product', function () {
        expect(fn () => DB::table('products')->where('id', $this->product->id)->update(['public_id' => (string) Str::ulid()]))
            ->toThrow(QueryException::class, 'products.public_id cannot be changed once written');
    });
});
