<?php

use App\Services\Basket;
use Tests\Support\FakeErp;

/**
 * @return array{data: list<array{id: string, type: string, name: array{en: string, bn: string}, has_children: bool}>}
 */
function storefrontAreaBody(string $id, string $type): array
{
    return ['data' => [['id' => $id, 'type' => $type, 'name' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'has_children' => true]]];
}

it('hands the ERP a division id, whose parent is written as a dash, to list its districts', function () {
    $erp = FakeErp::shop()
        ->on('GET /delivery/areas', fn () => [200, storefrontAreaBody('district:3:26', 'district')]);

    $this->getJson('/api/areas?parent='.urlencode('division:-:3'))
        ->assertOk()
        ->assertJsonPath('data.0.id', 'district:3:26');

    expect($erp->calls('GET', '/delivery/areas')[0]['query'])->toContain('parent=division%3A-%3A3');
});

it('lists the districts, upazilas and divisions, one level at a time', function (string $parent) {
    FakeErp::shop()
        ->on('GET /delivery/areas', fn () => [200, storefrontAreaBody('upazila:26:301', 'upazila')]);

    $this->getJson('/api/areas'.($parent === '' ? '' : '?parent='.urlencode($parent)))->assertOk()->assertJsonCount(1, 'data');
})->with(['', 'division:-:3', 'district:3:26']);

it('refuses a parent that is not an area id, without asking the ERP', function (string $parent) {
    $erp = FakeErp::shop();

    $this->getJson('/api/areas?parent='.urlencode($parent))->assertStatus(422)->assertJson(['data' => []]);

    expect($erp->calls('GET', '/delivery/areas'))->toBe([]);
})->with(['../etc', 'division:-', 'division:-:3:9', 'DIVISION:-:3', "district:3:26'--"]);

it('draws the checkout page with its three area selects and the ERP\'s own figures', function () {
    FakeErp::shop()->on('POST /checkout/quote', fn () => [200, ['data' => FakeErp::quoteData(2)]]);

    $html = storefrontGet($this, '/checkout', [], [Basket::COOKIE => storefrontBasket()])->assertOk()->getContent();

    expect($html)
        ->toContain('id="area-division"')
        ->toContain('id="area-district"')
        ->toContain('id="area-upazila"')
        ->toContain('co-summary')
        ->toContain('Cotton Panjabi')
        ->toContain('M / Navy')
        ->toContain('৳4,980.00')   // subtotal and line total, as the ERP sent them
        ->toContain('৳5,040.00')   // grand total
        ->toContain('Delivery is a flat ৳60.00');
});

it('leaves out the discount line when there is no discount', function () {
    FakeErp::shop()->on('POST /checkout/quote', fn () => [200, ['data' => FakeErp::quoteData(2)]]);

    expect(storefrontGet($this, '/checkout', [], [Basket::COOKIE => storefrontBasket()])->getContent())->not->toContain('co-discount');
});

it('shows the discount line, and the total after it, when the ERP applies one', function () {
    FakeErp::shop()->on('POST /checkout/quote', fn () => [200, ['data' => FakeErp::quoteData(2, [
        'discount' => FakeErp::moneyBody('100.00'), 'grand_total' => FakeErp::moneyBody('4940.00'),
    ])]]);

    expect(storefrontGet($this, '/checkout', [], [Basket::COOKIE => storefrontBasket()])->getContent())
        ->toContain('co-discount')
        ->toContain('৳4,940.00');
});
it('still draws a working form, with the total deferred, when the ERP cannot quote', function () {
    FakeErp::shop()->on('POST /checkout/quote', fn () => [503, ['error' => ['code' => 'unavailable', 'message' => 'down']]]);

    $html = storefrontGet($this, '/checkout', [], [Basket::COOKIE => storefrontBasket()])->assertOk()->getContent();

    expect($html)
        ->toContain('name="payment_method"')
        ->toContain('The total is confirmed on the next step.')
        ->not->toContain('co-grand');
});

it('keeps what the customer typed when a field is wrong, and says what to fix', function () {
    FakeErp::shop()->on('POST /checkout/quote', fn () => [200, ['data' => FakeErp::quoteData(2)]]);

    $response = storefrontPost($this, '/checkout', storefrontDetails(['phone' => '123', 'name' => 'Nusrat Jahan']), [], [Basket::COOKIE => storefrontBasket()]);

    $response->assertRedirect('/checkout')->assertSessionHasErrors('phone');

    $html = $this->followingRedirects()->withSession(['_old_input' => ['name' => 'Nusrat Jahan', 'phone' => '123'], 'errors' => $response->baseResponse->getSession()->get('errors')])
        ->withUnencryptedCookies([Basket::COOKIE => storefrontBasket()])->get('/checkout')->getContent();

    expect($html)->toContain('value="Nusrat Jahan"')->toContain('Please enter a valid mobile number.');
});

it('sends valid details on to the review step', function () {
    FakeErp::shop()->on('POST /checkout/quote', fn () => [200, ['data' => FakeErp::quoteData(2)]]);

    $response = storefrontPost($this, '/checkout', storefrontDetails(), [], [Basket::COOKIE => storefrontBasket()]);

    expect($response->headers->get('Location'))->toStartWith(url('/checkout/'));
    $response->assertSessionHasNoErrors();
});