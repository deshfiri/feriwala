<?php

use App\Providers\ApiServiceProvider;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // The suite runs as `testing`, not `local`, so the gate would close the docs.
    config(['scramble.public' => true]);
});

test('each API publishes an OpenAPI document', function (string $api, string $title) {
    $document = $this->getJson("docs/{$api}.json")->assertOk()->json();

    expect($document['openapi'])->toStartWith('3.')
        ->and($document['info']['title'])->toBe($title)
        ->and($document['paths'])->not->toBeEmpty();
})->with([
    'mobile' => ['mobile', 'Feriwala Mobile API'],
    'storefront' => ['storefront', 'Feriwala Storefront API'],
]);

test('the mobile document declares bearer authentication and leaves login public', function () {
    $document = $this->getJson('docs/mobile.json')->json();

    expect($document['components']['securitySchemes'])->toHaveCount(1)
        ->and($document['paths']['/auth/login']['post']['security'] ?? null)->toBe([])
        ->and($document['paths']['/auth/me']['get'])->not->toHaveKey('security');
});

test('Swagger UI renders for each API', function (string $api) {
    $this->get("docs/{$api}")
        ->assertOk()
        ->assertSee("url: \"\\/docs\\/{$api}.json\"", false)
        ->assertDontSee('http://', false)
        ->assertSee('swagger-ui', false);
})->with(['mobile', 'storefront']);

test('the documentation is closed to the public outside local development', function () {
    config(['scramble.public' => false]);

    $this->get('docs/mobile')->assertForbidden();
    $this->getJson('docs/mobile.json')->assertForbidden();

    config(['scramble.public' => true]);

    $this->get('docs/mobile')->assertOk();
});

test('every mobile route is documented, so nothing ships invisible', function () {
    $document = $this->getJson('docs/mobile.json')->json();

    $documented = collect($document['paths'])
        ->flatMap(fn (array $operations, string $path) => collect($operations)
            ->keys()
            ->map(fn (string $method) => strtoupper($method).' '.$path))
        ->all();

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/mobile/v1/'))
        ->map(fn (RoutingRoute $route) => $route->methods()[0].' /'.substr($route->uri(), strlen('api/mobile/v1/')))
        ->values()
        ->all();

    expect(array_diff($routes, $documented))->toBe([]);
});

test('operations are filed under the module their route name gives', function (string $name, array $tags) {
    expect(ApiServiceProvider::tagFor($name))->toBe($tags);
})->with([
    ['mobile.v1.auth.login', ['Authentication']],
    ['mobile.v1.wallet.transactions.show', ['Wallet']],
    ['mobile.v1.admin.orders.index', ['Admin · Orders']],
    ['mobile.v1.supplier.listings.store', ['Supplier · Listings']],
    ['mobile.v1.wholesale.cart.items.store', ['Wholesale · Cart']],
    ['mobile.v1.wholesale.orders.index', ['Wholesale · Orders']],
    ['mobile.v1.health', ['System']],
    ['storefront.v1.products.index', ['Products']],
]);
