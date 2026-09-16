<?php

namespace App\Domain\Website\Queries;

use App\Domain\Catalog\Models\Product;
use App\Domain\Package\Models\Package;
use App\Domain\Website\Data\WebsitePricingTerms;
use App\Domain\Website\Models\WebsiteProductPriceRule;
use Carbon\CarbonImmutable;

/**
 * What a partner may charge for one product, right now (§15.1, P5-5).
 *
 * Resolution walks **outward** from the most specific rule, the same shape the
 * fee and tax resolvers use: a rule naming this product and this package wins
 * outright, then one naming the product, then one naming the package, and only
 * with none of those does the global rule apply. Specificity beats recency, so
 * a newer blanket rule cannot quietly override a decision made for one product.
 *
 * Where no rule states a bound, the **product's own** selling bounds stand
 * (§11.1's minimum, maximum and suggested selling price). They are edited where
 * the rest of the product is, and a resolver that ignored them would let a
 * partner price below a floor the catalogue already set.
 *
 * With nothing configured anywhere, a partner may price freely above zero. That
 * is deliberate: an unconfigured platform should let a shop trade rather than
 * silently freeze every price at nothing.
 */
class ResolveWebsitePriceRule
{
    public function for(Product $product, ?Package $package = null, ?CarbonImmutable $at = null): WebsitePricingTerms
    {
        $at ??= CarbonImmutable::now();
        $rule = $this->ruleFor($product, $package, $at);

        return new WebsitePricingTerms(
            allowsUserPricing: $rule->allows_user_pricing ?? true,

            // The rule first, then what the catalogue itself says about this
            // product, then nothing. `??` reads a missing rule as null.
            minimum: $rule->min_price_minor ?? $product->minimum_selling_price_minor,
            maximum: $rule->max_price_minor ?? $product->maximum_selling_price_minor,
            suggested: $rule->suggested_price_minor ?? $product->suggested_selling_price_minor,
            maxMarginPercent: $rule?->max_margin_percent,
            lockedFields: $this->lockedFields($rule),
        );
    }

    /**
     * The rule in force, most specific first.
     */
    public function ruleFor(Product $product, ?Package $package, ?CarbonImmutable $at = null): ?WebsiteProductPriceRule
    {
        $at ??= CarbonImmutable::now();

        $scopes = [
            ['product' => $product->id, 'package' => $package?->id],
            ['product' => $product->id, 'package' => null],
            ['product' => null, 'package' => $package?->id],
            ['product' => null, 'package' => null],
        ];

        foreach ($scopes as $scope) {
            $rule = WebsiteProductPriceRule::query()
                ->effectiveAt($at)
                ->when(
                    $scope['product'] === null,
                    fn ($query) => $query->whereNull('product_id'),
                    fn ($query) => $query->where('product_id', $scope['product']),
                )
                ->when(
                    $scope['package'] === null,
                    fn ($query) => $query->whereNull('package_id'),
                    fn ($query) => $query->where('package_id', $scope['package']),
                )
                // The latest window wins where two overlap at the same scope.
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();

            if ($rule !== null) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function lockedFields(?WebsiteProductPriceRule $rule): array
    {
        if ($rule === null) {
            return [];
        }

        return array_values(array_intersect(
            $rule->locked_fields,
            WebsiteProductPriceRule::LOCKABLE_FIELDS,
        ));
    }
}
