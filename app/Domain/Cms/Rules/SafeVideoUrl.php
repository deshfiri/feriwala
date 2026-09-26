<?php

namespace App\Domain\Cms\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A `video` section's `video_url` embeds a third party's player on the
 * public landing page, which is a materially different trust boundary from
 * a CTA or menu link (§34's "approved video use cases" — Stage 7 addendum):
 * this is never followed by a click, it is loaded automatically for every
 * visitor. Restricted to a closed host allow-list rather than any HTTPS
 * URL, so a compromised or careless edit can never embed an arbitrary
 * third-party page.
 */
class SafeVideoUrl implements ValidationRule
{
    /** @var array<int, string> */
    protected const ALLOWED_HOSTS = [
        'www.youtube.com',
        'youtube.com',
        'youtube-nocookie.com',
        'www.youtube-nocookie.com',
        'player.vimeo.com',
        'vimeo.com',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('The :attribute must be a URL.');

            return;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        if ($scheme !== 'https' || ! in_array($host, self::ALLOWED_HOSTS, true)) {
            $fail('The :attribute must be a YouTube or Vimeo URL.');
        }
    }
}
