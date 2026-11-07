<?php

use Tests\Support\FakeErp;

it('draws the home page from what the ERP sends', function () {
    FakeErp::shop([FakeErp::productBody('cotton-panjabi')], [
        'branding' => ['logo_url' => 'https://cdn.shop.test/logo.png', 'banner_url' => 'https://cdn.shop.test/banner.png'],
    ]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('https://cdn.shop.test/banner.png')
        ->toContain('https://cdn.shop.test/logo.png')
        ->toContain('Good things')
        ->toContain('Cotton Panjabi')
        ->toContain('/category/panjabi')
        ->toContain('hello@shop.test')
        ->toContain('01700000000')
        ->toContain('Cash on delivery');
});

it('keeps nothing from the design that it was drawn on', function () {
    FakeErp::shop([FakeErp::productBody('cotton-panjabi')]);

    expect($this->get('/')->assertOk()->getContent())
        ->not->toContain('minimindsplay')
        ->not->toContain('Mini Minds')
        ->not->toContain('Smart Education Board')
        ->not->toContain('STEM & Robotics');
});

it('shows no category navigation or tiles when neither the ERP nor its products have categories', function () {
    FakeErp::shop([FakeErp::productBody('cotton-panjabi', 'FW-1-M', true, ['categories' => []])])
        ->on('GET /categories', fn () => [200, ['data' => [], 'meta' => ['has_more' => false, 'next_cursor' => null]]]);

    expect($this->get('/')->assertOk()->getContent())
        ->not->toContain('category-navigation')
        ->not->toContain('shop-by-category-title');
});

it('takes the categories from the products when the ERP lists none of its own', function () {
    FakeErp::shop([FakeErp::productBody('cotton-panjabi', 'FW-1-M', true, ['categories' => [['id' => '01G', 'slug' => 'gadgets', 'name' => 'Gadgets']]])])
        ->on('GET /categories', fn () => [200, ['data' => [], 'meta' => ['has_more' => false, 'next_cursor' => null]]]);

    expect($this->get('/')->assertOk()->getContent())
        ->toContain('/category/gadgets')
        ->toContain('Gadgets');
});

it('lets a short category list stay put', function () {
    FakeErp::shop([FakeErp::productBody('cotton-panjabi')])
        ->on('GET /categories', fn () => [200, ['data' => storefrontCategories(3), 'meta' => ['has_more' => false, 'next_cursor' => null]]]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('category-marquee--static');
    expect(substr_count($html, 'class="category-marquee__group"'))->toBe(1);
});

it('loops a long category list', function () {
    FakeErp::shop([FakeErp::productBody('cotton-panjabi')])
        ->on('GET /categories', fn () => [200, ['data' => storefrontCategories(9), 'meta' => ['has_more' => false, 'next_cursor' => null]]]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('category-marquee--static');
    expect(substr_count($html, 'class="category-marquee__group"'))->toBe(2);
});

it('shows a message queued for the visitor', function () {
    FakeErp::shop();

    storefrontGet($this, '/cart', ['storefront_messages' => [['type' => 'success', 'message' => 'Added to your cart.']]])
        ->assertOk()
        ->assertSee('flash flash-success', false)
        ->assertSee('Added to your cart.');
});

it('does not take Laravel\'s own flash data for a visitor message', function () {
    FakeErp::shop();

    storefrontGet($this, '/cart', ['_flash' => ['old' => ['status'], 'new' => []]])
        ->assertOk();
});
