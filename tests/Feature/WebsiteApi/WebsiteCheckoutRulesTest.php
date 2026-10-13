<?php

use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Order\WebsiteCheckoutRules;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * The checkout rules order placement and the quote share (P2). Placement's own
 * behaviour is held by the order intake suite; these pin the shared answers.
 */
beforeEach(function () {
    $this->rules = app(WebsiteCheckoutRules::class);
    $this->website = Website::factory()->forAccount(websiteTestAccount())->active()->create();
});

/**
 * The code of the refusal `$step` raises, or null.
 */
function websiteCheckoutRefusal(Closure $step): ?string
{
    try {
        $step();
    } catch (WebsiteOrderRefused $refused) {
        return $refused->errorCode;
    }

    return null;
}

it('refuses a website that is not live, as order placement always has', function () {
    expect(websiteCheckoutRefusal(fn () => $this->rules->assertAcceptingOrders($this->website)))->toBeNull();

    $this->website->forceFill(['status' => WebsiteStatus::Maintenance])->save();

    expect(websiteCheckoutRefusal(fn () => $this->rules->assertAcceptingOrders($this->website)))
        ->toBe('website_not_accepting_orders');
});

it('refuses what a storefront may not say about payment', function () {
    $rules = $this->rules;
    $website = $this->website;

    expect(websiteCheckoutRefusal(fn () => $rules->assertPaymentRequest($website, 'barter')))->toBe('payment_method_unavailable')
        ->and(websiteCheckoutRefusal(fn () => $rules->assertPaymentRequest($website, 'cod')))->toBe('payment_method_unavailable')
        ->and(websiteCheckoutRefusal(fn () => $rules->assertPaymentRequest($website, 'online', 'paid')))->toBe('payment_unverified')
        ->and(websiteCheckoutRefusal(fn () => $rules->assertPaymentRequest($website, 'online', null, 'https://elsewhere.example/thanks')))->toBe('return_url_not_allowed')
        ->and(websiteCheckoutRefusal(fn () => $rules->assertPaymentRequest($website, 'online', 'pending')))->toBeNull();
});

it('refuses any coupon', function () {
    expect(websiteCheckoutRefusal(fn () => $this->rules->assertNoCoupon('EID10')))->toBe('coupon_not_applicable')
        ->and(websiteCheckoutRefusal(fn () => $this->rules->assertNoCoupon(null)))->toBeNull()
        ->and(websiteCheckoutRefusal(fn () => $this->rules->assertNoCoupon('')))->toBeNull();
});

it('holds cash on delivery to the website\'s own limit', function () {
    $this->website->forceFill(['payment_config' => ['cod' => ['enabled' => true, 'maximum_amount' => '5000.00']]])->save();
    $within = Money::fromDecimal('5000.00', Currency::BDT);
    $over = Money::fromDecimal('5000.01', Currency::BDT);

    expect($this->rules->codAllows($this->website, $within))->toBeTrue()
        ->and($this->rules->codAllows($this->website, $over))->toBeFalse()
        ->and(websiteCheckoutRefusal(fn () => $this->rules->assertWithinCodLimit($this->website, $over)))->toBe('cod_limit_exceeded')
        ->and($this->rules->paymentMethods($this->website, Currency::BDT))->toContain('cod');
});

it('refuses a gateway that is not switched on', function () {
    expect(websiteCheckoutRefusal(fn () => $this->rules->gatewayFor('no-such-gateway', Currency::BDT)))
        ->toBe('payment_method_unavailable');
});
