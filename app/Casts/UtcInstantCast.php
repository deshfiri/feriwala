<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a `TIMESTAMPTZ` column into an exact instant, written in UTC.
 *
 * Eloquent's own datetime cast writes a value in the application's timezone
 * with no offset, so a `TIMESTAMPTZ` column would read it in whatever timezone
 * the database session happens to have. This cast writes the offset every time,
 * so the stored instant never depends on a session setting, and reads it back
 * in the application's timezone.
 *
 * {@see self::toDatabase()} is the same conversion for a query binding.
 *
 * @implements CastsAttributes<CarbonImmutable|null, mixed>
 */
class UtcInstantCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'UTC')->setTimezone((string) config('app.timezone'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (! $value instanceof DateTimeInterface) {
            throw new InvalidArgumentException(sprintf('The [%s] attribute must be a date-time instance.', $key));
        }

        return [$key => self::toDatabase($value)];
    }

    /**
     * The instant as a `TIMESTAMPTZ` literal carrying its UTC offset.
     */
    public static function toDatabase(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone('UTC')->format('Y-m-d H:i:s.uP');
    }
}
