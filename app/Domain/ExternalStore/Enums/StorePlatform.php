<?php

namespace App\Domain\ExternalStore\Enums;

/**
 * The store platforms Feriwala can connect to (D29).
 *
 * Only platforms with a working driver are listed. Another is added with its
 * driver, not before.
 */
enum StorePlatform: string
{
    case Dfcommerce = 'dfcommerce';

    public function label(): string
    {
        return match ($this) {
            self::Dfcommerce => 'DFCOMMERCE',
        };
    }
}
