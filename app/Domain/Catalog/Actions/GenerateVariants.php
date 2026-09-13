<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Build every combination of the chosen attribute values at once (§11.1).
 *
 * The variation builder behind the product editor. It adds nothing
 * {@see ManageVariants} does not already allow — every combination is created
 * through that action, so the product lock, the one-value-per-attribute rule,
 * the sibling consistency rule and the audit entry all apply exactly as they do
 * for a variation added by hand.
 *
 * What it decides itself:
 *
 *   - **combinations that already exist are left alone** and counted, so
 *     running the builder again after adding a colour creates only the new
 *     colour's combinations;
 *   - **the whole build is one transaction**. Half a size run is a catalogue
 *     nobody asked for, so a refusal part-way undoes what came before it;
 *   - **each SKU is made from the product's**, one segment per value, and a
 *     suffix is added when that SKU is taken — anywhere in the one namespace
 *     products and variations share;
 *   - prices are left blank, so the product's figures apply until someone sets
 *     an override.
 */
class GenerateVariants
{
    /**
     * The most combinations one build may create.
     *
     * Three attributes of five values each is 125; a builder that allowed any
     * pick would let one click lock a product for as long as it takes to write
     * thousands of rows.
     */
    public const MAX_COMBINATIONS = 100;

    public const SKU_MAX_LENGTH = 64;

    public function __construct(
        protected ManageVariants $variants,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<int, string>  $valuePublicIds
     * @return array{created: array<int, string>, skipped: int}
     *
     * @throws AuthorizationException
     * @throws CatalogRefused
     */
    public function handle(User $actor, Product $product, array $valuePublicIds): array
    {
        if (! CatalogPolicy::canCreate($actor)) {
            throw new AuthorizationException('You may not create variations.');
        }

        return $this->database->transaction(function () use ($actor, $product, $valuePublicIds) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $groups = $this->groupsFrom($valuePublicIds);

            $count = $groups->reduce(fn (int $carry, Collection $group) => $carry * $group->count(), 1);

            if ($count > self::MAX_COMBINATIONS) {
                throw CatalogRefused::tooManyCombinations($count, self::MAX_COMBINATIONS);
            }

            $existing = $locked->variants()->pluck('combination_key')->all();
            $created = [];
            $skipped = 0;

            foreach ($this->combinations($groups) as $combination) {
                $key = ProductVariant::combinationKeyFor(array_map(
                    fn (ProductAttributeValue $value) => $value->id,
                    $combination,
                ));

                if (in_array($key, $existing, true)) {
                    $skipped++;

                    continue;
                }

                $variant = $this->variants->create($actor, $locked, [
                    'sku' => $this->skuFor($locked, $combination),
                    'values' => array_map(fn (ProductAttributeValue $value) => $value->public_id, $combination),
                    'is_active' => true,
                ]);

                $existing[] = $key;
                $created[] = $variant->sku;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * The chosen values, one group per attribute, in the order the catalogue
     * lists attributes and their values — the order SKUs and labels read in.
     *
     * @param  array<int, string>  $publicIds
     * @return Collection<int, Collection<int, ProductAttributeValue>>
     *
     * @throws CatalogRefused
     */
    protected function groupsFrom(array $publicIds): Collection
    {
        $values = ProductAttributeValue::query()
            ->with('attribute')
            ->whereIn('public_id', array_values(array_unique($publicIds)))
            ->get();

        if ($values->isEmpty()) {
            throw CatalogRefused::noAttributes();
        }

        return $values->toBase()
            ->sortBy(fn (ProductAttributeValue $value) => [
                $value->attribute->sort_order,
                $value->attribute->name,
                $value->sort_order,
                $value->id,
            ])
            ->groupBy('product_attribute_id')
            ->map(fn (Collection $group) => $group->values())
            ->values();
    }

    /**
     * Every combination taking one value from each group.
     *
     * @param  Collection<int, Collection<int, ProductAttributeValue>>  $groups
     * @return array<int, array<int, ProductAttributeValue>>
     */
    protected function combinations(Collection $groups): array
    {
        $combinations = [[]];

        foreach ($groups as $group) {
            $next = [];

            foreach ($combinations as $partial) {
                foreach ($group as $value) {
                    $next[] = [...$partial, $value];
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * The product's SKU followed by one segment per value: `FW-1043-M-NAVY`.
     *
     * A value with no Latin letters or digits to make a segment from — "লাল" —
     * contributes the end of its public id instead, which is stable and cannot
     * collide with another value of the same attribute. A taken SKU gains `-2`,
     * `-3` and so on; the database trigger still holds the namespace against a
     * concurrent writer.
     *
     * @param  array<int, ProductAttributeValue>  $combination
     *
     * @throws CatalogRefused
     */
    protected function skuFor(Product $product, array $combination): string
    {
        $segments = array_map(function (ProductAttributeValue $value) {
            $segment = trim((string) preg_replace('/[^A-Z0-9]+/', '-', mb_strtoupper(Str::ascii($value->value))), '-');

            return $segment !== '' ? $segment : mb_strtoupper(mb_substr($value->public_id, -6));
        }, $combination);

        $base = mb_substr($product->sku.'-'.implode('-', $segments), 0, self::SKU_MAX_LENGTH);
        $base = rtrim($base, '-._');

        for ($attempt = 1; $attempt <= 50; $attempt++) {
            $suffix = $attempt === 1 ? '' : '-'.$attempt;
            $candidate = mb_substr($base, 0, self::SKU_MAX_LENGTH - mb_strlen($suffix)).$suffix;

            if (! $this->skuTaken($candidate)) {
                return $candidate;
            }
        }

        throw CatalogRefused::noFreeSku($base);
    }

    protected function skuTaken(string $sku): bool
    {
        return Product::query()->where('sku', $sku)->exists()
            || ProductVariant::query()->where('sku', $sku)->exists();
    }
}
