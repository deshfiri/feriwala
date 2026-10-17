<?php

declare(strict_types=1);

use Storefront\Http\Csrf;
use Storefront\Http\Session;
use Storefront\Support\Env;
use Storefront\Support\I18n;
use Storefront\Support\Money;

/**
 * Template helpers. Everything a template prints goes through `e()`.
 */
if (! function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('t')) {
    /**
     * @param  array<string, string|int>  $replace
     */
    function t(string $key, array $replace = []): string
    {
        return I18n::t($key, $replace);
    }
}

if (! function_exists('url')) {
    function url(string $path = '/'): string
    {
        return '/'.ltrim($path, '/');
    }
}

if (! function_exists('asset')) {
    function asset(string $path): string
    {
        $file = dirname(__DIR__, 2).'/public/assets/'.ltrim($path, '/');
        $version = is_file($file) ? substr(md5_file($file) ?: '', 0, 8) : '0';

        return '/assets/'.ltrim($path, '/').'?v='.$version;
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

if (! function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (! function_exists('old')) {
    function old(string $key, string $default = ''): string
    {
        static $old = null;
        $old ??= Session::pullInput();

        $value = $old[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }
}

if (! function_exists('num')) {
    function num(int|string $value): string
    {
        return Money::localiseDigits((string) $value);
    }
}

if (! function_exists('app_name')) {
    function app_name(): string
    {
        return (string) Env::get('APP_NAME', 'Storefront');
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
