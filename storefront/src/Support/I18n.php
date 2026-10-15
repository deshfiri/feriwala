<?php

declare(strict_types=1);

namespace Storefront\Support;

/**
 * English and Bangla. The locale is the visitor's choice, remembered in a
 * cookie; the shop's own default decides when they have not made one.
 */
final class I18n
{
    public const SUPPORTED = ['en', 'bn'];

    private static string $locale = 'en';

    /** @var array<string, array<string, string>> */
    private static array $strings = [];

    public static function boot(?string $cookie, string $default = 'en'): void
    {
        $default = in_array($default, self::SUPPORTED, true) ? $default : 'en';

        self::$locale = $cookie !== null && in_array($cookie, self::SUPPORTED, true) ? $cookie : $default;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    public static function t(string $key, array $replace = []): string
    {
        $line = self::load(self::$locale)[$key] ?? self::load('en')[$key] ?? $key;

        foreach ($replace as $name => $value) {
            $value = (string) $value;

            // A whole number in a sentence takes the reader's digits; anything
            // with letters in it (a product name, a date) is left exactly as it is.
            if (self::$locale === 'bn' && ctype_digit($value)) {
                $value = Money::localiseDigits($value);
            }

            $line = str_replace(':'.$name, $value, $line);
        }

        return $line;
    }

    /**
     * @return array<string, string>
     */
    private static function load(string $locale): array
    {
        if (! isset(self::$strings[$locale])) {
            $file = dirname(__DIR__, 2).'/lang/'.$locale.'.php';
            $loaded = is_file($file) ? require $file : [];
            self::$strings[$locale] = is_array($loaded) ? $loaded : [];
        }

        return self::$strings[$locale];
    }
}
