<?php

use App\Domain\Cms\Actions\ManageRedirect;
use App\Domain\Cms\Exceptions\CmsRedirectRefused;
use App\Domain\Cms\Models\Redirect;
use Illuminate\Database\QueryException;

/*
 * Public-site redirects (§34.1) — never a chain, never a loop, never an
 * absolute/external destination (which would make this an open redirect).
 */

it('creates a plain redirect', function () {
    $redirect = app(ManageRedirect::class)->handle('/old-page', '/new-page');

    expect($redirect->from_path)->toBe('/old-page')
        ->and($redirect->to_path)->toBe('/new-page')
        ->and($redirect->status_code)->toBe(301);
});

it('refuses a redirect that would immediately loop back on itself', function () {
    Redirect::query()->create(['from_path' => '/a', 'to_path' => '/b']);

    expect(fn () => app(ManageRedirect::class)->handle('/b', '/a'))
        ->toThrow(CmsRedirectRefused::class);
});

it('refuses a redirect that would chain into an existing one', function () {
    Redirect::query()->create(['from_path' => '/a', 'to_path' => '/b']);

    // /c -> /a -> /b is a chain, not a single hop to a real destination.
    expect(fn () => app(ManageRedirect::class)->handle('/c', '/a'))
        ->toThrow(CmsRedirectRefused::class);
});

it('allows two independent redirects that do not chain into each other', function () {
    Redirect::query()->create(['from_path' => '/a', 'to_path' => '/b']);

    $redirect = app(ManageRedirect::class)->handle('/c', '/d');

    expect($redirect->to_path)->toBe('/d');
});

it('rejects an absolute external URL at the database boundary', function () {
    expect(fn () => Redirect::query()->create([
        'from_path' => '/old',
        'to_path' => 'https://evil.example.com',
    ]))->toThrow(QueryException::class);
});

it('rejects a redirect from a path to itself at the database boundary', function () {
    expect(fn () => Redirect::query()->create([
        'from_path' => '/same',
        'to_path' => '/same',
    ]))->toThrow(QueryException::class);
});

it('rejects a scheme-relative destination at the database boundary', function () {
    expect(fn () => Redirect::query()->create([
        'from_path' => '/old',
        'to_path' => '//evil.example.com',
    ]))->toThrow(QueryException::class);
});
