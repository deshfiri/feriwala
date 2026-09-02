<?php

namespace App\Http\Middleware;

use App\Support\Localization\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request's language (decision D6).
 *
 * Precedence, most specific first:
 *
 *   1. the authenticated user's saved preference
 *   2. the session, for a guest who has used the switcher
 *   3. the Accept-Language header
 *   4. the application default
 *
 * A signed-in user's stored choice wins over the browser header, because someone
 * who deliberately picked Bangla in the ERP should not be flipped back to English
 * by a browser configured elsewhere.
 */
class SetLocale
{
    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request)->value);

        return $next($request);
    }

    protected function resolve(Request $request): Locale
    {
        $user = $request->user();

        // The column arrives with the account module; until then this is simply skipped.
        if ($user !== null && filled($user->getAttribute('locale'))) {
            return Locale::parse($user->getAttribute('locale'));
        }

        if ($request->hasSession() && $request->session()->has(self::SESSION_KEY)) {
            return Locale::parse($request->session()->get(self::SESSION_KEY));
        }

        return $this->fromHeader($request);
    }

    protected function fromHeader(Request $request): Locale
    {
        $supported = array_map(fn (Locale $locale) => $locale->value, Locale::cases());

        $preferred = $request->getPreferredLanguage($supported);

        return Locale::parse($preferred);
    }
}
