<?php

use App\Http\Middleware\LoadStorefront;
use App\Services\Basket;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeErp;

/*
| D37: the delivery charge is the ERP's, Inside or Outside Dhaka by the chosen
| district, and the cart and checkout ask for fresh figures on every change
| without reloading. These cover the two endpoints the page calls.
*/

/**
 * POST JSON as the page's script does, with the basket cookie.
 *
 * @param  array<string, mixed>  $data
 */
function storefrontPostJson(TestCase $test, string $uri, array $data, string $basket): TestResponse
{
    // A JSON request carries cookies only "with credentials", as fetch() does.
    return $test->withSession(['_token' => 'tok'])
        ->withCredentials()
        ->withUnencryptedCookies([Basket::COOKIE => $basket])
        ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'tok']);
}

/**
 * The basket's quantity of one line.
 */
function storefrontBasketQuantity(string $token, string $sku = 'FW-1-M'): ?int
{
    foreach (Basket::forToken($token)->items() as $line) {
        if ($line['sku'] === $sku) {
            return $line['quantity'];
        }
    }

    return null;
}

describe('the checkout quote for the chosen area', function () {
    it('prices Inside Dhaka for a Dhaka district and sends the area to the ERP', function () {
        $erp = FakeErp::shop()->on('POST /checkout/quote', fn (?array $body) => [200, ['data' => FakeErp::quoteData(2, area: 'inside_dhaka', shipping: '80.00')]]);

        $response = storefrontPostJson($this, '/checkout/quote', ['area_id' => 'upazila:26:493'], storefrontBasket())->assertOk();

        $response->assertJsonPath('ready', true)->assertJsonPath('grand_total', '৳5,060.00');
        expect($response->json('totals_html'))->toContain('Inside Dhaka')->toContain('৳80.00')->toContain('৳5,060.00')
            ->and($response->json('note_html'))->toBe('Delivery Inside Dhaka: ৳80.00 per order.')
            ->and($erp->calls('POST', '/checkout/quote')[0]['body']['delivery'])->toBe(['area_id' => 'upazila:26:493']);
    });

    it('prices Outside Dhaka for any other district', function () {
        FakeErp::shop()->on('POST /checkout/quote', fn () => [200, ['data' => FakeErp::quoteData(2, area: 'outside_dhaka', shipping: '130.00')]]);

        $response = storefrontPostJson($this, '/checkout/quote', ['area_id' => 'district:30:41'], storefrontBasket())->assertOk();

        expect($response->json('totals_html'))->toContain('Outside Dhaka')->toContain('৳130.00')
            ->and($response->json('grand_total'))->toBe('৳5,110.00');
    });

    it('says Free delivery when the rate is zero', function () {
        FakeErp::shop()->on('POST /checkout/quote', fn () => [200, ['data' => FakeErp::quoteData(2, area: 'inside_dhaka', shipping: '0.00')]]);

        $response = storefrontPostJson($this, '/checkout/quote', ['area_id' => 'district:30:26'], storefrontBasket())->assertOk();

        expect($response->json('totals_html'))->toContain('Free delivery')
            ->and($response->json('note_html'))->toBe('Free delivery (Inside Dhaka).')
            ->and($response->json('ready'))->toBeTrue();
    });

    it('is not ready on a division alone: the goods are priced, the district is asked for', function () {
        $erp = FakeErp::shop()->on('POST /checkout/quote', fn () => [200, ['data' => FakeErp::quoteData(2, area: null)]]);

        $response = storefrontPostJson($this, '/checkout/quote', ['area_id' => 'division:-:30'], storefrontBasket())->assertOk();

        $response->assertJsonPath('ready', false)->assertJsonPath('grand_total', null);
        expect($response->json('totals_html'))->toContain('Choose your district')
            ->and($erp->calls('POST', '/checkout/quote')[0]['body'])->not->toHaveKey('delivery');
    });

    it('refuses something that is not an area id without asking the ERP', function () {
        $erp = FakeErp::shop();

        storefrontPostJson($this, '/checkout/quote', ['area_id' => "district:30:26'--"], storefrontBasket())
            ->assertStatus(422)
            ->assertJsonPath('ready', false);

        expect($erp->calls('POST', '/checkout/quote'))->toBe([]);
    });

    it('says the area could not be found when the ERP cannot resolve it', function () {
        FakeErp::shop()->on('POST /checkout/quote', fn () => [422, ['error' => ['code' => 'delivery_address_required', 'message' => 'x']]]);

        storefrontPostJson($this, '/checkout/quote', ['area_id' => 'district:99:999'], storefrontBasket())
            ->assertStatus(422)
            ->assertJsonPath('ready', false)
            ->assertJsonPath('message', 'We could not find that delivery area. Please choose your district again.');
    });

    it('fails closed when the delivery charge is not configured', function () {
        FakeErp::shop()->on('POST /checkout/quote', fn () => [409, ['error' => ['code' => 'website_not_accepting_orders', 'message' => 'Delivery charge is not configured.', 'details' => ['reason' => 'delivery_not_configured']]]]);

        storefrontPostJson($this, '/checkout/quote', ['area_id' => 'district:30:26'], storefrontBasket())
            ->assertStatus(409)
            ->assertJsonPath('ready', false)
            ->assertJsonPath('message', 'Delivery is not available just now. Please try again later.');
    });

    it('answers in Bangla for a Bangla visitor', function () {
        FakeErp::shop()->on('POST /checkout/quote', fn () => [200, ['data' => FakeErp::quoteData(2, area: 'inside_dhaka', shipping: '80.00')]]);

        $response = $this->withSession(['_token' => 'tok'])
            ->withCredentials()
            ->withUnencryptedCookies([Basket::COOKIE => storefrontBasket(), LoadStorefront::LOCALE_COOKIE => 'bn'])
            ->postJson('/checkout/quote', ['area_id' => 'district:30:26'], ['X-CSRF-TOKEN' => 'tok'])
            ->assertOk();

        expect($response->json('totals_html'))->toContain('ঢাকার ভেতরে');
    });
});

describe('placing the checkout details', function () {
    it('refuses details with no delivery area', function () {
        FakeErp::shop()->on('POST /checkout/quote', fn () => [200, ['data' => FakeErp::quoteData(2)]]);

        storefrontPost($this, '/checkout', storefrontDetails(['area_id' => '']), [], [Basket::COOKIE => storefrontBasket()])
            ->assertRedirect('/checkout')
            ->assertSessionHasErrors(['area_id' => 'Please choose your district so we can work out the delivery charge.']);
    });
});

describe('changing a quantity in the cart', function () {
    it('saves the quantity and answers with the ERP\'s fresh figures', function () {
        FakeErp::shop()->on('POST /checkout/quote', fn (?array $body) => [200, ['data' => FakeErp::quoteData((int) $body['items'][0]['quantity'], area: null)]]);
        $basket = storefrontBasket();

        $response = storefrontPostJson($this, '/cart/quantity', ['sku' => 'FW-1-M', 'quantity' => '3'], $basket)->assertOk();

        $response->assertJsonPath('quantity', 3)
            ->assertJsonPath('message', null)
            ->assertJsonPath('lines.FW-1-M.total', '৳7,470.00')
            ->assertJsonPath('checkout_blocked', false);
        expect($response->json('totals_html'))->toContain('৳7,470.00')->toContain('Worked out at checkout')
            ->and(storefrontBasketQuantity($basket))->toBe(3);
    });

    it('keeps the last confirmed quantity, and says why, when the ERP refuses the new one', function () {
        FakeErp::shop()->on('POST /checkout/quote', fn (?array $body) => (int) $body['items'][0]['quantity'] > 10
            ? [422, ['error' => ['code' => 'quantity_not_allowed', 'message' => 'x']]]
            : [200, ['data' => FakeErp::quoteData((int) $body['items'][0]['quantity'], area: null)]]);
        $basket = storefrontBasket();

        $response = storefrontPostJson($this, '/cart/quantity', ['sku' => 'FW-1-M', 'quantity' => '11'], $basket)->assertStatus(422);

        $response->assertJsonPath('quantity', 2)
            ->assertJsonPath('message', 'That quantity cannot be ordered for this item.')
            ->assertJsonPath('lines.FW-1-M.total', '৳4,980.00');
        expect(storefrontBasketQuantity($basket))->toBe(2);
    });

    it('keeps the last confirmed quantity when not that many are available', function () {
        FakeErp::shop()->on('POST /checkout/quote', function (?array $body) {
            $quantity = (int) $body['items'][0]['quantity'];
            $data = FakeErp::quoteData($quantity, area: null);
            $data['items'][0]['availability']['in_stock'] = $quantity <= 40;

            return [200, ['data' => $data]];
        });
        $basket = storefrontBasket();

        storefrontPostJson($this, '/cart/quantity', ['sku' => 'FW-1-M', 'quantity' => '999'], $basket)
            ->assertStatus(422)
            ->assertJsonPath('quantity', 2)
            ->assertJsonPath('message', 'Not that many are available. Please choose a smaller quantity.')
            ->assertJsonPath('checkout_blocked', false);

        expect(storefrontBasketQuantity($basket))->toBe(2);
    });

    it('refuses a quantity that is not a whole number of one or more', function (string $quantity) {
        FakeErp::shop()->on('POST /checkout/quote', fn () => [200, ['data' => FakeErp::quoteData(2, area: null)]]);
        $basket = storefrontBasket();

        storefrontPostJson($this, '/cart/quantity', ['sku' => 'FW-1-M', 'quantity' => $quantity], $basket)
            ->assertStatus(422)
            ->assertJsonPath('quantity', 2)
            ->assertJsonPath('message', 'Please enter a quantity of 1 or more.');

        expect(storefrontBasketQuantity($basket))->toBe(2);
    })->with(['0', '-1', 'abc', '1.5', '1001']);

    it('does not know a line that is not in the basket', function () {
        FakeErp::shop();

        storefrontPostJson($this, '/cart/quantity', ['sku' => 'NOPE-1', 'quantity' => '2'], storefrontBasket())->assertNotFound();
    });

    it('draws the cart with live quantity hooks and no total before an area is known', function () {
        FakeErp::shop()->on('POST /checkout/quote', fn () => [200, ['data' => FakeErp::quoteData(2, area: null)]]);

        $html = storefrontGet($this, '/cart', [], [Basket::COOKIE => storefrontBasket()])->assertOk()->getContent();

        expect($html)
            ->toContain('data-quantity-url="'.route('cart.quantity').'"')
            ->toContain('data-live-qty')
            ->toContain('data-confirmed="2"')
            ->toContain('Worked out at checkout')
            ->not->toContain('class="grand"');
    });
});
