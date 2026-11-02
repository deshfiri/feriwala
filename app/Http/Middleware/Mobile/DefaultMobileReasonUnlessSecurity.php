<?php

namespace App\Http\Middleware\Mobile;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mobile counterpart of {@see \App\Http\Middleware\DefaultReasonUnlessSecurity}.
 *
 * Same policy, same `config/reasons.php` lists: a reason is demanded only where
 * it is a security matter, and "No reason given." stands in everywhere else on
 * a staff screen. The lists are written against the web route names
 * (`admin.roles.*`), so a mobile route `mobile.v1.admin.roles.store` is matched
 * as `admin.roles.store` — which is also why mobile admin routes must be named
 * exactly like the web route they mirror.
 */
class DefaultMobileReasonUnlessSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $name = Str::after((string) $request->route()?->getName(), 'mobile.v1.');

        if (! Str::is(config('reasons.default_for', []), $name) || Str::is(config('reasons.required_for', []), $name)) {
            return $next($request);
        }

        $fallback = __('reasons.not_given');

        if (blank($request->input('reason'))) {
            $request->merge(['reason' => $fallback]);
        }

        // A batch decision carries one reason per entry.
        $entries = $request->input('entries');

        if (is_array($entries)) {
            foreach ($entries as $index => $entry) {
                if (is_array($entry) && blank($entry['reason'] ?? null)) {
                    $entries[$index]['reason'] = $fallback;
                }
            }

            $request->merge(['entries' => $entries]);
        }

        return $next($request);
    }
}
