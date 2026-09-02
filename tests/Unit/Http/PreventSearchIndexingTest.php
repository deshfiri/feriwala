<?php

use App\Http\Middleware\PreventSearchIndexing;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

function passThrough(): Response
{
    return (new PreventSearchIndexing)->handle(
        Request::create('/orders'),
        fn () => new Response('ok'),
    );
}

it('sets the robots header on the response', function () {
    expect(passThrough()->headers->get('X-Robots-Tag'))
        ->toBe('noindex, nofollow, noarchive, nosnippet');
});

it('keeps the page out of results and out of caches', function () {
    $directive = passThrough()->headers->get('X-Robots-Tag');

    // noarchive matters as much as noindex: without it a cached copy of a
    // partner's data can be served long after the leak is closed.
    expect($directive)->toContain('noindex')
        ->and($directive)->toContain('nofollow')
        ->and($directive)->toContain('noarchive')
        ->and($directive)->toContain('nosnippet');
});

it('passes the response through unchanged otherwise', function () {
    expect(passThrough()->getContent())->toBe('ok')
        ->and(passThrough()->getStatusCode())->toBe(200);
});
