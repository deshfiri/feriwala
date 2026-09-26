<?php

use App\Domain\Cms\Actions\ManageRedirect;
use App\Domain\Cms\Actions\PublishPage;
use App\Domain\Cms\Actions\SaveSectionDraft;
use App\Domain\Cms\Enums\SectionKind;

/*
 * The public site's completeness routes (§34.1, Stage 8): an admin-managed
 * redirect actually being served to a real visitor, and the sitemap/robots
 * pair search engines expect to find.
 */

it('serves an enabled redirect for its exact path', function () {
    app(ManageRedirect::class)->handle('/old-page', '/register');

    $response = $this->get('/old-page');

    $response->assertRedirect('/register');
    $response->assertStatus(301);
});

it('404s a path with no page and no matching redirect', function () {
    $this->get('/nothing-configured-here')->assertNotFound();
});

it('never serves a disabled redirect', function () {
    $redirect = app(ManageRedirect::class)->handle('/old-page', '/register');
    app(ManageRedirect::class)->setEnabled($redirect, false);

    $this->get('/old-page')->assertNotFound();
});

it('lists the published home page in the sitemap and nothing when it is not live', function () {
    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml');

    expect($this->get('/sitemap.xml')->getContent())->not->toContain('<url>');

    $page = cmsTestPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, cmsTestHeroContent());
    app(PublishPage::class)->handle($page);

    $content = $this->get('/sitemap.xml')->getContent();

    expect($content)->toContain('<url>')
        ->and($content)->toContain(url('/'));
});

it('serves a robots.txt that points at the sitemap', function () {
    $response = $this->get('/robots.txt');

    $response->assertOk();
    expect($response->getContent())
        ->toContain('Sitemap: '.route('sitemap'))
        ->toContain('User-agent: *');
});
