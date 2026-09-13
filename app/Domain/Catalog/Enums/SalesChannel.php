<?php

namespace App\Domain\Catalog\Enums;

use App\Domain\Package\Enums\PackageFeature;

/**
 * The two ways a partner uses the central catalogue (§10, §11.1).
 *
 * Kept apart everywhere: a product is switched on for each separately, a
 * package grants each separately, and a business account browses each on its
 * own screen through its own query. Dropshipping selection and wholesale
 * purchasing use the same products, but they are different acts with different
 * prices, and nothing lets one stand in for the other.
 */
enum SalesChannel: string
{
    case Dropshipping = 'dropshipping';
    case Wholesale = 'wholesale';

    /**
     * The product column holding this channel's status.
     */
    public function column(): string
    {
        return $this->value.'_status';
    }

    public function enabled(): ProductStatus
    {
        return match ($this) {
            self::Dropshipping => ProductStatus::DropshippingEnabled,
            self::Wholesale => ProductStatus::WholesaleEnabled,
        };
    }

    public function disabled(): ProductStatus
    {
        return match ($this) {
            self::Dropshipping => ProductStatus::DropshippingDisabled,
            self::Wholesale => ProductStatus::WholesaleDisabled,
        };
    }

    /**
     * The package facility that has to allow this channel (§8.1).
     */
    public function facility(): PackageFeature
    {
        return match ($this) {
            self::Dropshipping => PackageFeature::DropshippingEnabled,
            self::Wholesale => PackageFeature::WholesaleEnabled,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Dropshipping => 'Dropshipping',
            self::Wholesale => 'Wholesale',
        };
    }
}
