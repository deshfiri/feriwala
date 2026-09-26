<?php

namespace App\Domain\Cms\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Route;

/**
 * A section's CTA points somewhere Feriwala controls: a route name already
 * registered (checked live, so a later route rename fails a future save
 * rather than silently 404ing a published page), or a site-relative path.
 * Never an arbitrary external URL and never `javascript:`/`data:`/a
 * scheme-relative `//host` — a CTA is not a menu link, and §34 draws that
 * line narrower on purpose (see {@see SafeMenuUrl} for the menu case, which
 * is deliberately more permissive).
 */
class SafeCtaHref implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('The :attribute must be a route name or a site-relative path.');

            return;
        }

        if (str_starts_with($value, '/')) {
            if (str_starts_with($value, '//')) {
                $fail('The :attribute may not be a scheme-relative URL.');
            }

            return;
        }

        if (! Route::has($value)) {
            $fail('The :attribute must be a registered route name or start with "/".');
        }
    }
}
