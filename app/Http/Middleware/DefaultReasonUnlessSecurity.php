<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A reason is only demanded where it is a security matter.
 *
 * Everywhere else, leaving the reason out is fine: the action is still audited
 * as ever, with a plain "No reason given." standing in, so nothing downstream
 * that stores or checks a reason has to change. The routes that stay strict
 * are listed in `config/reasons.php` — who can do what, sign-ins, suspensions,
 * and moving or correcting money by hand.
 *
 * Applies to staff screens only (the `admin.*` routes) and to the two partner
 * forms that ask for a free-text reason; a customer's return reason, a
 * Supplier's reason for declining and every other value somebody chose from a
 * list are theirs and are never filled in for them.
 */
class DefaultReasonUnlessSecurity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $name = (string) $request->route()?->getName();

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
