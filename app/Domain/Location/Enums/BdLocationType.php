<?php

namespace App\Domain\Location\Enums;

/**
 * One level of the Bangladesh administrative directory.
 *
 * Ordered top to bottom on purpose: {@see self::child()} and
 * {@see self::parent()} walk the hierarchy by enum order rather than a
 * separate lookup table.
 */
enum BdLocationType: string
{
    case Division = 'division';
    case District = 'district';
    case Upazila = 'upazila';
    case Union = 'union';

    /**
     * The upstream JSON collection key for this level, in a given language.
     */
    public function sourceCollectionKey(string $locale): string
    {
        return match ($this) {
            self::Division => "divisions_{$locale}",
            self::District => "districts_{$locale}",
            self::Upazila => "upazilas_{$locale}",
            self::Union => "unions_{$locale}",
        };
    }

    public function child(): ?self
    {
        return match ($this) {
            self::Division => self::District,
            self::District => self::Upazila,
            self::Upazila => self::Union,
            self::Union => null,
        };
    }

    public function parent(): ?self
    {
        return match ($this) {
            self::Division => null,
            self::District => self::Division,
            self::Upazila => self::District,
            self::Union => self::Upazila,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Division => 'Division',
            self::District => 'District',
            self::Upazila => 'Thana / Upazila',
            self::Union => 'Union',
        };
    }
}
