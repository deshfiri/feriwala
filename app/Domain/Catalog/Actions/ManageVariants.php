<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

/**
 * Creating, editing and removing a product's variations (§11.1, §12).
 *
 * The product row is locked for every write, which is what makes the rules
 * below race-free: two administrators adding "M, Navy" at the same moment queue
 * on the lock, and the second finds the first's variant rather than both finding
 * none. The unique index on the combination holds it regardless.
 *
 *   - One value per attribute. A shirt is not both M and L.
 *   - Every variant of a product is chosen by the same attributes. A storefront
 *     offering Size and Colour cannot also offer a variant chosen by Material.
 *   - A combination is fixed once created. A different combination is a new
 *     variant, and the old one is switched off — changing it in place would make
 *     every later record of the old variant describe something it never was.
 */
class ManageVariants
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  `values` holds attribute-value public ids
     *
     * @throws CatalogRefused
     */
    public function create(User $actor, Product $product, array $attributes): ProductVariant
    {
        // §12 names "unauthorized Product variations" outright.
        CatalogPolicy::authorize(CatalogPolicy::canCreate($actor), 'You may not create variations.');

        return $this->database->transaction(function () use ($actor, $product, $attributes) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $values = $this->valuesFrom($attributes['values'] ?? []);

            $this->assertOneValuePerAttribute($values);
            $this->assertConsistentWithSiblings($locked, $values);

            $key = ProductVariant::combinationKeyFor($values->pluck('id')->all());

            if ($locked->variants()->where('combination_key', $key)->exists()) {
                throw CatalogRefused::duplicateCombination();
            }

            $variant = ProductVariant::create([
                ...$this->fields($attributes),
                'product_id' => $locked->id,
                'combination_key' => $key,
                'currency_code' => $locked->currency_code,
                'sort_order' => (int) $locked->variants()->max('sort_order') + 1,
            ]);

            $this->database->table('product_variant_values')->insert(
                $values->map(fn (ProductAttributeValue $value) => [
                    'product_variant_id' => $variant->id,
                    'product_attribute_id' => $value->product_attribute_id,
                    'product_attribute_value_id' => $value->id,
                ])->all(),
            );

            $this->record($actor, 'catalog.variant_created', $variant, after: [
                ...$this->snapshot($variant),
                'values' => $values->pluck('value')->all(),
            ]);

            return $variant;
        });
    }

    /**
     * Change a variant's identifiers, figures or availability — never its
     * combination.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, ProductVariant $variant, array $attributes): ProductVariant
    {
        CatalogPolicy::authorize(CatalogPolicy::canEdit($actor), 'You may not edit variations.');

        return $this->database->transaction(function () use ($actor, $variant, $attributes) {
            Product::query()->whereKey($variant->product_id)->lockForUpdate()->firstOrFail();

            $before = $this->snapshot($variant);

            $variant->fill($this->fields($attributes))->save();

            $this->record($actor, 'catalog.variant_updated', $variant, $before, $this->snapshot($variant));

            return $variant->refresh();
        });
    }

    /**
     * Remove a variant outright — only while its product is still a draft.
     *
     * @throws CatalogRefused
     */
    public function delete(User $actor, ProductVariant $variant): void
    {
        CatalogPolicy::authorize(CatalogPolicy::canDelete($actor), 'You may not delete variations.');

        $this->database->transaction(function () use ($actor, $variant) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($variant->product_id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ProductStatus::Draft) {
                throw CatalogRefused::variantNotDraft();
            }

            $this->record($actor, 'catalog.variant_deleted', $variant, before: $this->snapshot($variant));

            // Its own price tiers go with it, named rather than cascaded.
            ProductPriceTier::query()->where('product_variant_id', $variant->id)->delete();

            $variant->delete();
        });
    }

    /**
     * @param  array<int, string>  $publicIds
     * @return Collection<int, ProductAttributeValue>
     *
     * @throws CatalogRefused
     */
    protected function valuesFrom(array $publicIds): Collection
    {
        $values = ProductAttributeValue::query()
            ->with('attribute')
            ->whereIn('public_id', array_values(array_unique($publicIds)))
            ->get();

        if ($values->isEmpty()) {
            throw CatalogRefused::noAttributes();
        }

        return $values->toBase();
    }

    /**
     * @param  Collection<int, ProductAttributeValue>  $values
     *
     * @throws CatalogRefused
     */
    protected function assertOneValuePerAttribute(Collection $values): void
    {
        foreach ($values->groupBy('product_attribute_id') as $group) {
            if ($group->count() > 1) {
                /** @var ProductAttributeValue $first */
                $first = $group->first();

                throw CatalogRefused::attributeRepeated($first->attribute->name);
            }
        }
    }

    /**
     * @param  Collection<int, ProductAttributeValue>  $values
     *
     * @throws CatalogRefused
     */
    protected function assertConsistentWithSiblings(Product $product, Collection $values): void
    {
        /** @var ProductVariant|null $sibling */
        $sibling = $product->variants()->with('values.attribute')->first();

        if ($sibling === null) {
            return;
        }

        $expected = $sibling->values->pluck('product_attribute_id')->sort()->values()->all();
        $given = $values->pluck('product_attribute_id')->sort()->values()->all();

        if ($expected !== $given) {
            throw CatalogRefused::inconsistentAttributes(
                $sibling->values
                    ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                    ->map(fn (ProductAttributeValue $value) => $value->attribute->name)
                    ->implode(' and '),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function fields(array $attributes): array
    {
        $fields = [];

        if (array_key_exists('sku', $attributes)) {
            $fields['sku'] = mb_strtoupper(trim((string) $attributes['sku']));
        }

        if (array_key_exists('barcode', $attributes)) {
            $fields['barcode'] = blank($attributes['barcode']) ? null : $attributes['barcode'];
        }

        // Null means the product's own figure applies, so the two cannot drift.
        foreach (['wholesale_price_minor', 'base_cost_minor'] as $figure) {
            if (array_key_exists($figure, $attributes)) {
                $fields[$figure] = $attributes[$figure] === null || $attributes[$figure] === ''
                    ? null
                    : Money::of((int) $attributes[$figure], Currency::base());
            }
        }

        if (array_key_exists('is_active', $attributes)) {
            $fields['is_active'] = (bool) $attributes['is_active'];
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(ProductVariant $variant): array
    {
        return [
            'product_id' => $variant->product_id,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'combination_key' => $variant->combination_key,
            'wholesale_price_minor' => $variant->wholesale_price_minor?->minorUnits,
            'base_cost_minor' => $variant->base_cost_minor?->minorUnits,
            'is_active' => $variant->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function record(
        User $actor,
        string $action,
        ProductVariant $variant,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: ProductVariant::class,
            auditableId: $variant->id,
            before: $before,
            after: $after,
            module: 'catalog',
        ));
    }
}
