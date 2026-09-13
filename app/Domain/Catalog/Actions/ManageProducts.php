<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductMediaStore;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;

/**
 * Creating, editing and removing central products (§11.1, §12).
 *
 * The only write path for a product's master data. §12 is enforced before this
 * is reached — the controller asks the policy — and nothing here takes a
 * business account, because nothing a business account does writes a product.
 *
 * Figures arrive as integer minor units and are turned into {@see Money} here,
 * in the base currency, so no caller can hand the model a float or a currency
 * nobody chose (D4).
 */
class ManageProducts
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected ProductMediaStore $mediaStore,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes): Product
    {
        return $this->database->transaction(function () use ($actor, $attributes) {
            $product = Product::create([
                ...$this->fields($attributes),
                'currency_code' => Currency::base()->value,
                'status' => Product::STATUS_DRAFT,
            ]);

            $this->record($actor, 'catalog.product_created', $product, after: $this->snapshot($product));

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, Product $product, array $attributes): Product
    {
        return $this->database->transaction(function () use ($actor, $product, $attributes) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $before = $this->snapshot($locked);

            $locked->fill($this->fields($attributes))->save();

            $this->record($actor, 'catalog.product_updated', $locked, $before, $this->snapshot($locked));

            return $locked->refresh();
        });
    }

    /**
     * Remove a product outright — only ever a draft.
     *
     * Anything further along is archived instead. The rows that will come to
     * point at a product (orders, invoices, website selections) are what make a
     * delete dangerous, and none of them can exist for a product that was never
     * offered.
     *
     * A draft's own variations and media are removed with it, **explicitly**:
     * the foreign keys restrict rather than cascade, so nothing disappears
     * without this method naming it, and the audit entry lists what went. The
     * media files are deleted only after the removal commits.
     *
     * @throws CatalogRefused
     */
    public function delete(User $actor, Product $product): void
    {
        $paths = [];

        $this->database->transaction(function () use ($actor, $product, &$paths) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Product::STATUS_DRAFT) {
                throw CatalogRefused::productNotDraft();
            }

            $paths = ProductMedia::query()->where('product_id', $locked->id)->pluck('path')->all();

            $this->record($actor, 'catalog.product_deleted', $locked, before: [
                ...$this->snapshot($locked),
                'variants' => ProductVariant::query()->where('product_id', $locked->id)->pluck('sku')->all(),
                'media' => $paths,
            ]);

            ProductMedia::query()->where('product_id', $locked->id)->delete();
            ProductPriceTier::query()->where('product_id', $locked->id)->delete();

            // A variant's value links are part of the variant and go with it.
            ProductVariant::query()->where('product_id', $locked->id)->delete();

            $locked->delete();
        });

        foreach ($paths as $path) {
            $this->mediaStore->delete($path);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function fields(array $attributes): array
    {
        $fields = [];

        foreach (['name', 'short_description', 'description', 'barcode'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $fields[$field] = $attributes[$field];
            }
        }

        // Upper-case, matching the CHECK on the column: one SKU is one product
        // to a warehouse picker whatever the casing it was typed in.
        if (array_key_exists('sku', $attributes)) {
            $fields['sku'] = mb_strtoupper(trim((string) $attributes['sku']));
        }

        /*
         * A slug is taken only when one was typed. `HasSlug` makes one from the
         * name on creation and then leaves it alone, because renaming a product
         * is not a reason to break every storefront link to it.
         */
        if (array_key_exists('slug', $attributes) && filled($attributes['slug'])) {
            $fields['slug'] = $attributes['slug'];
        }

        if (array_key_exists('category_id', $attributes)) {
            $fields['category_id'] = Category::query()
                ->where('public_id', $attributes['category_id'])
                ->value('id');
        }

        if (array_key_exists('brand_id', $attributes)) {
            $fields['brand_id'] = blank($attributes['brand_id'])
                ? null
                : Brand::query()->where('public_id', $attributes['brand_id'])->value('id');
        }

        foreach (['base_cost_minor', 'wholesale_price_minor'] as $figure) {
            if (array_key_exists($figure, $attributes)) {
                $fields[$figure] = Money::of((int) $attributes[$figure], Currency::base());
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(Product $product): array
    {
        return [
            'sku' => $product->sku,
            'slug' => $product->slug,
            'name' => $product->name,
            'barcode' => $product->barcode,
            'category_id' => $product->category_id,
            'brand_id' => $product->brand_id,
            'base_cost_minor' => $product->base_cost_minor->minorUnits,
            'wholesale_price_minor' => $product->wholesale_price_minor->minorUnits,
            'currency_code' => $product->currency_code,
            'status' => $product->status,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function record(
        User $actor,
        string $action,
        Product $product,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: Product::class,
            auditableId: $product->id,
            before: $before,
            after: $after,
            module: 'catalog',
        ));
    }
}
