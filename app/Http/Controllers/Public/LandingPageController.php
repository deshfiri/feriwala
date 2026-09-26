<?php

namespace App\Http\Controllers\Public;

use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Support\PublishedPageReader;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public landing page (§4, §34) — informational only. Never requires
 * authentication, never exposes draft content (the reader only ever reads a
 * live revision's frozen snapshot — see {@see PublishedPageReader}), and
 * never renders a product catalogue, wholesale/dropshipping browsing, a cart
 * or checkout: those stay behind the authenticated ERP panel this page only
 * points visitors toward.
 */
class LandingPageController extends Controller
{
    public function __invoke(PublishedPageReader $reader): Response
    {
        $locale = app()->getLocale();
        $page = $reader->render('home', $locale);

        if ($page === null) {
            return Inertia::render('public/landing-unavailable', [
                'seo' => ['title' => config('app.name'), 'robots' => 'noindex, nofollow'],
            ]);
        }

        $sections = $page['sections'];

        $packageSectionIndex = collect($sections)->search(
            fn (array $section) => $section['kind'] === SectionKind::PackagePreview->value,
        );

        if ($packageSectionIndex !== false) {
            $sections[$packageSectionIndex]['packages'] = $reader->publicPackagePreviews();
        }

        return Inertia::render('public/landing', [
            'seo' => $page['seo'],
            'sections' => $sections,
            'menus' => $page['menus'],
        ]);
    }
}
