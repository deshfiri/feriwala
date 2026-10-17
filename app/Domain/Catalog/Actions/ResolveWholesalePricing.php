<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\WholesalePricingMethod;
use App\Domain\Catalog\WholesalePriceCalculator;
use App\Support\Money\Money;
use InvalidArgumentException;

/**
 * Settles a wholesale price from its method — the one step product and
 * variation writes share, so a price cannot be worked out two ways.
 *
 * Fixed keeps the price a person typed and drops any percentage. Percentage
 * ignores whatever price the browser sent and calculates it from the base cost
 * and the markup, so the stored figure is always the server's own.
 */
class ResolveWholesalePricing
{
    public function __construct(protected WholesalePriceCalculator $calculator) {}

    /**
     * @return array{method: WholesalePricingMethod, markup: string|null, price: Money|null}
     *
     * @throws InvalidArgumentException when a percentage lacks its base cost or markup
     */
    public function handle(
        WholesalePricingMethod $method,
        ?Money $baseCost,
        ?string $markupPercentage,
        ?Money $fixedPrice,
    ): array {
        if (! $method->isPercentage()) {
            return ['method' => $method, 'markup' => null, 'price' => $fixedPrice];
        }

        if ($baseCost === null || $markupPercentage === null || trim($markupPercentage) === '') {
            throw new InvalidArgumentException('Percentage pricing needs a base cost and a markup percentage.');
        }

        $markup = WholesalePriceCalculator::normalizePercentage($markupPercentage);

        return [
            'method' => $method,
            'markup' => $markup,
            'price' => $this->calculator->wholesalePrice($baseCost, $markup),
        ];
    }
}
