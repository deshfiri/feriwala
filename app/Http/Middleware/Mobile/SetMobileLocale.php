<?php

namespace App\Http\Middleware\Mobile;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the language a mobile response is written in.
 *
 * The web app keeps the choice in a session. A token client has none, so it is
 * resolved per request: an explicit `Accept-Language` wins, then the person's
 * saved `locale`, then the application default. Only locales that have a
 * `lang/` directory are accepted (`en`, `bn`).
 */
class SetMobileLocale
{
    /**
     * @var array<int, string>
     */
    public const SUPPORTED = ['en', 'bn'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->requested($request) ?? $this->saved($request) ?? config('app.locale');

        App::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    protected function requested(Request $request): ?string
    {
        $preferred = $request->getPreferredLanguage(self::SUPPORTED);

        return $request->headers->has('Accept-Language') && in_array($preferred, self::SUPPORTED, true)
            ? $preferred
            : null;
    }

    protected function saved(Request $request): ?string
    {
        $user = $request->user();

        return $user instanceof User && in_array($user->locale, self::SUPPORTED, true)
            ? $user->locale
            : null;
    }
}
