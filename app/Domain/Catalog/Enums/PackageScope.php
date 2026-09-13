<?php

namespace App\Domain\Catalog\Enums;

/**
 * Which packages a product is offered to (§11.1 "Package eligibility").
 *
 * An explicit choice rather than "no rows means everybody". An empty list that
 * silently meant "all packages" would turn deleting the last selection into
 * offering the product to every partner on the platform — the opposite of what
 * the person clearing the list wanted.
 */
enum PackageScope: string
{
    case AllPackages = 'all';
    case SelectedPackages = 'selected';

    public function label(): string
    {
        return match ($this) {
            self::AllPackages => 'Every package',
            self::SelectedPackages => 'Only the selected packages',
        };
    }
}
