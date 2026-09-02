<?php

namespace App\Domain\Package\Enums;

/**
 * How a package feature's stored value is interpreted.
 */
enum PackageFeatureType: string
{
    case Boolean = 'boolean';

    /** An integer cap, where null means unlimited. */
    case Limit = 'limit';

    case Text = 'text';

    public function cast(?string $value): bool|int|string|null
    {
        // For a limit, null is meaningful — it is "unlimited", not "missing".
        if ($value === null) {
            return $this === self::Limit ? null : null;
        }

        return match ($this) {
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOL),
            self::Limit => (int) $value,
            self::Text => $value,
        };
    }

    public function serialise(bool|int|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Boolean => $value ? '1' : '0',
            self::Limit => (string) (int) $value,
            self::Text => (string) $value,
        };
    }
}
