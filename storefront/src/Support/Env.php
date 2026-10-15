<?php

declare(strict_types=1);

namespace Storefront\Support;

/**
 * Configuration from the process environment, with a `.env` file as the
 * fallback. Nothing secret is ever read from a file that is served.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            self::$values[trim($key)] = $value;
        }
    }

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $fromProcess = getenv($key);

        if (is_string($fromProcess) && $fromProcess !== '') {
            return $fromProcess;
        }

        return self::$values[$key] ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return $value !== null && ctype_digit($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        return $value === null ? $default : in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function isLocal(): bool
    {
        return self::get('APP_ENV', 'production') === 'local';
    }
}
