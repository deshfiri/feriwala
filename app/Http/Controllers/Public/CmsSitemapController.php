<?php

namespace App\Http\Controllers\Public;

use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Support\PublishedPageReader;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use SimpleXMLElement;

/**
 * The public site's sitemap (§34.1, Stage 8 completion) — lists only what a
 * visitor could actually reach right now, so it is built from the same
 * liveness check {@see PublishedPageReader} itself uses (published,
 * currently within any scheduled start/end window) rather than a separate
 * one that could drift out of step and list a page that 404s.
 *
 * There is exactly one publicly routable page today (`home`); a second one
 * only has to be added to the loop below once it has a real public route,
 * not designed speculatively here first.
 */
class CmsSitemapController extends Controller
{
    public function __invoke(PublishedPageReader $reader): Response
    {
        $xml = new SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>',
        );

        $home = Page::query()->where('slug', 'home')->with('currentPublishedRevision')->first();

        if ($home !== null && $reader->render('home', app()->getLocale()) !== null) {
            $entry = $xml->addChild('url');
            $entry->addChild('loc', htmlspecialchars(url('/'), ENT_XML1));

            if ($home->currentPublishedRevision?->published_at !== null) {
                $entry->addChild('lastmod', $home->currentPublishedRevision->published_at->toAtomString());
            }
        }

        return response($xml->asXML() ?: '', 200)->header('Content-Type', 'application/xml');
    }
}
