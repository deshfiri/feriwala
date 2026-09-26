<?php

namespace App\Domain\Cms\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A menu's `external_url` must resolve somewhere Feriwala can vouch for:
 * either a site-relative path (including an in-page anchor like
 * `/#how-it-works`, which has no named route of its own), or an absolute
 * URL using a scheme a link can safely carry a visitor to. Never
 * `javascript:`, `data:`, `vbscript:`, or a bare scheme-relative `//host`
 * (which silently follows the current protocol to an attacker's host).
 */
class SafeMenuUrl implements ValidationRule
{
    protected const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('The :attribute must be a URL.');

            return;
        }

        if (str_starts_with($value, '//')) {
            $fail('The :attribute may not be a scheme-relative URL.');

            return;
        }

        if (str_starts_with($value, '/')) {
            return;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if ($scheme === '' || ! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            $fail('The :attribute must be a site-relative path, or use http, https, mailto or tel.');
        }
    }
}
