<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps authenticated ERP pages out of search engines (requirements.txt §34.2).
 *
 * Authentication alone is not enough. A shared link, a misconfigured proxy, or a
 * crawler following a leaked URL can all reach a page that was never meant to be
 * indexed, and a cached search result outlives the mistake that caused it.
 *
 * `noindex` keeps the page out of results; `nofollow` stops crawlers walking
 * further into the panel; `noarchive` prevents a cached copy being served after
 * the page is fixed; `nosnippet` keeps fragments of a partner's data out of
 * result pages.
 *
 * Only the public landing site and partner storefronts are meant to be indexed,
 * and those routes do not carry this middleware.
 */
class PreventSearchIndexing
{
    public const DIRECTIVE = 'noindex, nofollow, noarchive, nosnippet';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', self::DIRECTIVE);

        return $response;
    }
}
