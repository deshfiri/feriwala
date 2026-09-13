<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\WholesalePriceResolver;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;

/**
 * Replace the quantity pricing of a product, or of one of its variations
 * (§11.1).
 *
 * The whole set in one write, because a tier table is one decision: each band
 * is only meaningful against the band before it. Saving rows one at a time would
 * leave a moment where "from 10 units" exists and "from 50 units" does not, and
 * anybody reading then gets a price nobody set.
 *
 * Each band must cost no more per unit than the one before it, and the first no
 * more than the base price. Buying more for more per unit is a typing mistake,
 * and the kind that is only noticed when a buyer complains.
 */
class SetPriceTiers
{
    /**
     * Enough bands for any real price sheet; more is a spreadsheet, not a tier
     * table.
     */
    public const MAX_TIERS = 10;

    public function __construct(
        protected RecordAuditLog $audit,
        protected WholesalePriceResolver $prices,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<int, array{min_quantity: int|string, unit_price_minor: int|string}>  $tiers
     *
     * @throws CatalogRefused
     */
    public function handle(User $actor, Product $product, ?ProductVariant $variant, array $tiers): void
    {
        $this->database->transaction(function () use ($actor, $product, $variant, $tiers) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($variant !== null && $variant->product_id !== $locked->id) {
                throw CatalogRefused::variantOfAnotherProduct();
            }

            $bands = collect($tiers)
                ->map(fn (array $tier) => [
                    'min_quantity' => (int) $tier['min_quantity'],
                    'unit_price' => Money::of((int) $tier['unit_price_minor'], Currency::from($locked->currency_code)),
                ])
                ->sortBy('min_quantity')
                ->values();

            if ($bands->count() > self::MAX_TIERS) {
                throw CatalogRefused::tooManyTiers(self::MAX_TIERS);
            }

            $previous = $this->prices->basePrice($locked, $variant);
            $seen = [];

            foreach ($bands as $band) {
                if ($band['min_quantity'] < 2) {
                    throw CatalogRefused::tierQuantityTooLow();
                }

                // A band nobody can ever reach is a price nobody is charged.
                if ($locked->max_order_quantity !== null && $band['min_quantity'] > $locked->max_order_quantity) {
                    throw CatalogRefused::tierAboveMaximumOrder($band['min_quantity'], $locked->max_order_quantity);
                }

                if (isset($seen[$band['min_quantity']])) {
                    throw CatalogRefused::tierQuantityRepeated($band['min_quantity']);
                }

                if ($band['unit_price']->greaterThan($previous)) {
                    throw CatalogRefused::tierPriceRises($band['min_quantity']);
                }

                $seen[$band['min_quantity']] = true;
                $previous = $band['unit_price'];
            }

            $scope = ProductPriceTier::query()
                ->where('product_id', $locked->id)
                ->where('product_variant_id', $variant?->id);

            $before = $this->describe((clone $scope)->orderBy('min_quantity')->get()->all());

            (clone $scope)->delete();

            foreach ($bands as $band) {
                ProductPriceTier::create([
                    'product_id' => $locked->id,
                    'product_variant_id' => $variant?->id,
                    'min_quantity' => $band['min_quantity'],
                    'unit_price_minor' => $band['unit_price'],
                ]);
            }

            $this->audit->handle(new AuditEntry(
                action: 'catalog.price_tiers_set',
                actorId: $actor->id,
                auditableType: $variant === null ? Product::class : ProductVariant::class,
                auditableId: $variant->id ?? $locked->id,
                before: ['tiers' => $before],
                after: ['tiers' => $bands->map(fn (array $band) => [
                    'min_quantity' => $band['min_quantity'],
                    'unit_price_minor' => $band['unit_price']->minorUnits,
                ])->all()],
                module: 'catalog',
            ));
        });
    }

    /**
     * @param  array<int, ProductPriceTier>  $tiers
     * @return array<int, array{min_quantity: int, unit_price_minor: int}>
     */
    protected function describe(array $tiers): array
    {
        return array_map(fn (ProductPriceTier $tier) => [
            'min_quantity' => $tier->min_quantity,
            'unit_price_minor' => $tier->unit_price_minor->minorUnits,
        ], $tiers);
    }
}
