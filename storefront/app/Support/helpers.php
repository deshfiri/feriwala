<?php

use App\Support\Money;
use Illuminate\Http\Request;

/**
 * Template helpers. Everything a template prints goes through Blade's escaping.
 */
if (! function_exists('t')) {
    /**
     * A line of the shop's own wording, in the visitor's language. A whole number
     * in a sentence takes the reader's digits; anything with letters in it (a
     * product name, a date) is left exactly as it is.
     *
     * @param  array<string, string|int>  $replace
     */
    function t(string $key, array $replace = []): string
    {
        foreach ($replace as $name => $value) {
            $value = (string) $value;
            $replace[$name] = ctype_digit($value) ? Money::localiseDigits($value) : $value;
        }

        return (string) __($key, $replace);
    }
}

if (! function_exists('money')) {
    /**
     * @param  array<string, mixed>|null  $value
     */
    function money(?array $value): string
    {
        return Money::display($value);
    }
}

if (! function_exists('num')) {
    function num(int|string $value): string
    {
        return Money::localiseDigits((string) $value);
    }
}

if (! function_exists('versioned_asset')) {
    /**
     * A file under public/assets, with a short content hash so a changed file is
     * never served from a browser's year-long cache.
     */
    function versioned_asset(string $path): string
    {
        $file = public_path('assets/'.ltrim($path, '/'));
        $version = is_file($file) ? substr(md5_file($file) ?: '', 0, 8) : '0';

        return asset('assets/'.ltrim($path, '/')).'?v='.$version;
    }
}

if (! function_exists('flash_message')) {
    /**
     * Queue a message for the next page the visitor sees. Several may be queued;
     * the layout shows and clears them.
     */
    function flash_message(string $type, string $message): void
    {
        session()->push('storefront_messages', ['type' => $type, 'message' => $message]);
    }
}

if (! function_exists('plain_text')) {
    /**
     * Product copy from the ERP as text: tags dropped, whitespace tidied. The
     * result is escaped on output; markup from outside is never trusted.
     */
    function plain_text(?string $html, int $limit = 0): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $html)));

        return $limit > 0 && mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)).'…' : $text;
    }
}

if (! function_exists('paragraphs')) {
    /**
     * Description text split into paragraphs on blank lines, each escaped.
     *
     * @return list<string>
     */
    function paragraphs(?string $html): array
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n\n", (string) $html)), ENT_QUOTES, 'UTF-8');
        $parts = preg_split('/\n{2,}/', trim($text)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p) => $p !== ''));
    }
}

if (! function_exists('on_colour')) {
    /**
     * Black or white text, whichever reads better on a shop's chosen colour
     * (WCAG relative luminance). A partner may pick a pale brand colour; a white
     * button label on it would not be legible.
     */
    function on_colour(string $hex): string
    {
        if (preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $hex, $m) !== 1) {
            return '#ffffff';
        }

        $linear = static function (string $channel): float {
            $value = hexdec($channel) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        $luminance = 0.2126 * $linear($m[1]) + 0.7152 * $linear($m[2]) + 0.0722 * $linear($m[3]);

        // White wins on anything darker than mid-grey luminance.
        return $luminance > 0.36 ? '#111111' : '#ffffff';
    }
}

if (! function_exists('safe_url')) {
    /**
     * A URL from the ERP, allowed only when it is plain http(s). Anything else
     * (javascript:, data:) becomes an empty string.
     */
    function safe_url(?string $url): string
    {
        if ($url === null || $url === '') {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }
}

if (! function_exists('request_string')) {
    /**
     * One input as a trimmed string; an array, a missing key or null is the default.
     */
    function request_string(Request $request, string $key, string $default = ''): string
    {
        $value = $request->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }
}

if (! function_exists('own_page_or')) {
    /**
     * Where to send somebody back to: the page they came from when it is this
     * shop's, otherwise the fallback.
     */
    function own_page_or(Request $request, string $fallback = '/'): string
    {
        $referer = $request->headers->get('referer');

        if ($referer !== null) {
            $parts = parse_url($referer);
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            $host = (string) ($parts['host'] ?? '');

            if ($host !== '' && ($host === $appHost || $host === $request->getHost())) {
                return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
            }
        }

        return $fallback;
    }
}
