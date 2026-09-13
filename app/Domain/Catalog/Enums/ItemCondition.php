<?php

namespace App\Domain\Catalog\Enums;

/**
 * The condition a product is sold in, as schema.org names it (§34.3).
 */
enum ItemCondition: string
{
    case New = 'new';
    case Refurbished = 'refurbished';
    case Used = 'used';

    /**
     * The schema.org enumeration member partner storefronts put in JSON-LD.
     */
    public function schemaUrl(): string
    {
        return match ($this) {
            self::New => 'https://schema.org/NewCondition',
            self::Refurbished => 'https://schema.org/RefurbishedCondition',
            self::Used => 'https://schema.org/UsedCondition',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Refurbished => 'Refurbished',
            self::Used => 'Used',
        };
    }
}
