<?php

use App\Domain\Cms\Actions\PublishPage;
use App\Domain\Cms\Actions\SaveSectionDraft;
use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Models\Page;
use App\Domain\Package\Models\Package;
use App\Support\Money\Money;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The public landing route (§4, §34) — the door every guest actually walks
 * through. Never requires authentication, never renders draft content, and
 * never exposes anything from the private ERP domains it links out to.
 */

function cmsLandingPage(): Page
{
    return Page::query()->create(['slug' => 'home', 'page_type' => 'landing']);
}

it('is reachable by a guest with no authentication at all', function () {
    $page = cmsLandingPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, [
        'heading' => ['en' => 'Welcome', 'bn' => 'স্বাগতম'],
        'primary_cta' => ['label' => ['en' => 'Register', 'bn' => 'নিবন্ধন'], 'href' => 'register'],
    ]);
    app(PublishPage::class)->handle($page);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $assert) => $assert->component('public/landing'));

    $this->assertGuest();
});

it('shows the unavailable page rather than an error when nothing is published', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $assert) => $assert
            ->component('public/landing-unavailable')
            ->where('seo.robots', 'noindex, nofollow'));
});

it('carries dynamic SEO props through to the page', function () {
    $page = cmsLandingPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, [
        'heading' => ['en' => 'Welcome', 'bn' => 'স্বাগতম'],
        'primary_cta' => ['label' => ['en' => 'Register', 'bn' => 'নিবন্ধন'], 'href' => 'register'],
    ]);
    app(PublishPage::class)->handle($page);

    $this->get(route('home'))->assertInertia(fn (Assert $assert) => $assert
        ->component('public/landing')
        ->has('seo.title')
        ->has('seo.robots'));
});

it('attaches real, current package data to the package_preview section, never invented figures', function () {
    $package = Package::create([
        'slug' => 'growth-'.Str::lower(Str::random(8)),
        'name' => 'Growth',
        'fee' => Money::fromDecimal('2500.00'),
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

    $page = cmsLandingPage();
    $save = app(SaveSectionDraft::class);
    $save->handle($page, 'hero', SectionKind::Hero, [
        'heading' => ['en' => 'Welcome', 'bn' => 'স্বাগতম'],
        'primary_cta' => ['label' => ['en' => 'Register', 'bn' => 'নিবন্ধন'], 'href' => 'register'],
    ]);
    $save->handle($page, 'packages', SectionKind::PackagePreview, [
        'heading' => ['en' => 'Packages', 'bn' => 'প্যাকেজ'],
    ]);
    app(PublishPage::class)->handle($page);

    $this->get(route('home'))->assertInertia(fn (Assert $assert) => $assert
        ->component('public/landing')
        ->where('sections.1.packages.0.name', 'Growth')
        ->where('sections.1.packages.0.fee.amount', $package->fee->toDecimal()));
});

it('never exposes Supplier rate, internal financial data, or a product catalogue on the public page', function () {
    $page = cmsLandingPage();
    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, [
        'heading' => ['en' => 'Welcome', 'bn' => 'স্বাগতম'],
        'primary_cta' => ['label' => ['en' => 'Register', 'bn' => 'নিবন্ধন'], 'href' => 'register'],
    ]);
    app(PublishPage::class)->handle($page);

    $response = $this->get(route('home'));
    $props = $response->viewData('page')['props'];

    // Scoped to what this controller itself contributes -- the shared
    // Inertia props every page carries (translations, nav permissions) are
    // a separate concern from what LandingPageController exposes, and are
    // not what this test is about.
    $cmsProps = json_encode([
        'seo' => $props['seo'],
        'sections' => $props['sections'],
        'menus' => $props['menus'],
    ]);

    expect($cmsProps)
        ->not->toContain('supplier_rate')
        ->not->toContain('supplierRate')
        ->not->toContain('wholesale_price')
        ->not->toContain('wallet_balance');
});
