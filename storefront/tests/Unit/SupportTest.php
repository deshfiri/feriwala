<?php

use App\Services\Catalog;
use App\Services\Site;
use App\Support\Money;

it('groups a decimal string without a float', function () {
    expect(Money::group('1234.5'))->toBe('1,234.50')
        ->and(Money::group('0'))->toBe('0.00')
        ->and(Money::group('1000000'))->toBe('1,000,000.00')
        ->and(Money::group('-1234'))->toBe('-1,234.00')
        ->and(Money::group('12abc'))->toBe('12abc')
        ->and(Money::group('9007199254740993.10'))->toBe('9,007,199,254,740,993.10');
});

it('shows the ERP\'s own formatted figure, or builds one', function () {
    expect(Money::display(['amount' => '2490.00', 'currency' => 'BDT', 'formatted' => '৳2,490.00']))->toBe('৳2,490.00')
        ->and(Money::display(['amount' => '2490', 'currency' => 'BDT']))->toBe('৳2,490.00')
        ->and(Money::display(['amount' => '5', 'currency' => 'XYZ']))->toBe('XYZ 5.00')
        ->and(Money::display(null))->toBe('');
});

it('knows a zero amount exactly', function () {
    expect(Money::isZero(['amount' => '0.00']))->toBeTrue()
        ->and(Money::isZero(['amount' => '0']))->toBeTrue()
        ->and(Money::isZero(['amount' => '0.01']))->toBeFalse()
        ->and(Money::isZero(null))->toBeTrue();
});

it('compares decimal strings exactly, past what a float can hold', function () {
    expect(Catalog::decimalLess('99.99', '100.00'))->toBeTrue()
        ->and(Catalog::decimalLess('100.00', '99.99'))->toBeFalse()
        ->and(Catalog::decimalLess('0.1', '0.10001'))->toBeTrue()
        ->and(Catalog::decimalLess('2490.00', '2490'))->toBeFalse()
        ->and(Catalog::decimalLess('9007199254740992.01', '9007199254740992.02'))->toBeTrue();
});

it('picks the cheapest variant as the starting price', function () {
    $product = ['variants' => [
        ['price' => ['amount' => '2490.00', 'currency' => 'BDT']],
        ['price' => ['amount' => '990.50', 'currency' => 'BDT']],
        ['price' => null],
    ]];

    expect(Catalog::startingPrice($product)['amount'])->toBe('990.50')
        ->and(Catalog::startingPrice(['variants' => []]))->toBeNull();
});

it('takes a whole number in a Bangla sentence into Bangla digits, and leaves a name alone', function () {
    app()->setLocale('bn');

    expect(t('cart.max_limited', ['max' => '5']))->toContain('৫')
        ->and(t('cart.added', ['name' => 'Cotton 100']))->toContain('Cotton 100')
        ->and(num(2490))->toBe('২৪৯০')
        ->and(Money::display(['amount' => '2490', 'currency' => 'BDT']))->toBe('৳২,৪৯০.০০');

    app()->setLocale('en');

    expect(t('cart.max_limited', ['max' => '5']))->toContain('5')
        ->and(num(2490))->toBe('2490');
});

it('turns product copy into plain text, escaped later by Blade', function () {
    expect(plain_text('<p>Soft <b>cotton</b></p>   and  more'))->toBe('Soft cotton and more')
        ->and(plain_text('abcdefghij', 5))->toBe('abcd…')
        ->and(plain_text(null))->toBe('');
});

it('splits a description into paragraphs and strips every tag', function () {
    expect(paragraphs("Line one.\n\nLine two."))->toBe(['Line one.', 'Line two.'])
        ->and(paragraphs('<p>One</p><p>Two <i>x</i></p>'))->toBe(['One', 'Two x'])
        ->and(paragraphs(null))->toBe([]);
});

it('allows only http and https URLs from the ERP', function () {
    expect(safe_url('https://cdn.test/a.jpg'))->toBe('https://cdn.test/a.jpg')
        ->and(safe_url('javascript:alert(1)'))->toBe('')
        ->and(safe_url('data:text/html;base64,AAAA'))->toBe('')
        ->and(safe_url(null))->toBe('');
});

it('chooses legible text for a brand colour', function () {
    expect(on_colour('#000000'))->toBe('#ffffff')
        ->and(on_colour('#ffffff'))->toBe('#111111')
        ->and(on_colour('not-a-colour'))->toBe('#ffffff');
});

it('only follows a banner link that is this shop or https', function () {
    expect(Site::bannerLink('/products'))->toBe(['url' => '/products', 'external' => false])
        ->and(Site::bannerLink('https://promo.test/x'))->toBe(['url' => 'https://promo.test/x', 'external' => true])
        ->and(Site::bannerLink('javascript:alert(1)'))->toBeNull()
        ->and(Site::bannerLink('//evil.test'))->toBeNull()
        ->and(Site::bannerLink('https://user:pw@evil.test/'))->toBeNull()
        ->and(Site::bannerLink("https://evil.test/\n"))->toBeNull()
        ->and(Site::bannerLink('http://promo.test/'))->toBeNull()
        ->and(Site::bannerLink(null))->toBeNull();
});

it('lets only a hex colour into the stylesheet and reads the gateways the ERP named', function () {
    $site = Site::normalise(['name' => 'S', 'branding' => ['primary_color' => 'red;}</style>'], 'checkout' => ['gateways' => [
        ['name' => 'eps', 'label' => 'EPS'], ['name' => 'Bad Name!'], ['name' => 'bkash'],
    ]]]);

    expect($site['branding']['primary_color'])->toBe('#1f2937')
        ->and($site['gateways'])->toBe([['name' => 'eps', 'label' => 'EPS'], ['name' => 'bkash', 'label' => 'bkash']])
        ->and($site['known'])->toBeTrue()
        ->and(Site::normalise([])['known'])->toBeFalse()
        ->and(Site::normalise([])['status'])->toBe('unavailable');
});
