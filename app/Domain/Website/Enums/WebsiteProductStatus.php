<?php

namespace App\Domain\Website\Enums;

/**
 * Where one selected product stands on one storefront (§15).
 *
 * Three states, and the difference between the last two matters: a product
 * that was **never** published is not the same as one a partner took down.
 * §15 asks for both a publication and an unpublication, and a storefront that
 * conflated them could not say whether a product had ever been on sale.
 *
 * Selection itself is the first state. A partner choosing a product has not yet
 * priced it, and publishing something at a price nobody set is how a shop ends
 * up selling at the wrong figure.
 */
enum WebsiteProductStatus: string
{
    case Selected = 'selected';
    case Published = 'published';
    case Unpublished = 'unpublished';

    /**
     * Whether customers can see it on the storefront.
     */
    public function isOnSale(): bool
    {
        return $this === self::Published;
    }

    public function label(): string
    {
        return match ($this) {
            self::Selected => 'Selected',
            self::Published => 'Published',
            self::Unpublished => 'Unpublished',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
