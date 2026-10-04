<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Enums\ItemCondition;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Policies\CatalogPolicy;
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
 * Figures arrive as exact decimal Taka strings and are turned into {@see Money}
 * here, in the base currency, so no caller can hand the model a float or a
 * currency nobody chose (D4).
 */
class ManageProducts
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected ProductMediaStore $mediaStore,
        protected DatabaseManager $database,
        protected GenerateProductIdentifiers $identifiers,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes): Product
    {
        // §12's first sentence, held here as well as at the controller.
        CatalogPolicy::authorize(CatalogPolicy::canCreate($actor), 'You may not create products.');

        return $this->database->transaction(function () use ($actor, $attributes) {
            $product = Product::create([
                ...$this->fields($attributes),
                'currency_code' => Currency::base()->value,
                'status' => ProductStatus::Draft,
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
        CatalogPolicy::authorize(CatalogPolicy::canEdit($actor), 'You may not edit products.');

        return $this->database->transaction(function () use ($actor, $product, $attributes) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $before = $this->snapshot($locked);

            $locked->fill($this->fields($attributes, $locked))->save();

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
        CatalogPolicy::authorize(CatalogPolicy::canDelete($actor), 'You may not delete products.');

        $media = collect();

        $this->database->transaction(function () use ($actor, $product, &$media) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ProductStatus::Draft) {
                throw CatalogRefused::productNotDraft();
            }

            // A draft sent back from review has a history, and the history
            // is append-only: it is archived, not deleted.
            if ($locked->statusHistory()->exists()) {
                throw CatalogRefused::productHasHistory();
            }

            $media = ProductMedia::query()->where('product_id', $locked->id)->get(['path', 'disk']);

            $this->record($actor, 'catalog.product_deleted', $locked, before: [
                ...$this->snapshot($locked),
                'variants' => ProductVariant::query()->where('product_id', $locked->id)->pluck('sku')->all(),
                'media' => $media->pluck('path')->all(),
            ]);

            ProductMedia::query()->where('product_id', $locked->id)->delete();
            ProductPriceTier::query()->where('product_id', $locked->id)->delete();
            $locked->eligiblePackages()->detach();
            $locked->eligibleAccounts()->detach();

            // Its recommendations, and every other product's recommendation of it.
            $this->database->table('product_related')
                ->where('product_id', $locked->id)
                ->orWhere('related_product_id', $locked->id)
                ->delete();

            // A variant's value links are part of the variant and go with it.
            ProductVariant::query()->where('product_id', $locked->id)->delete();

            $locked->delete();
        });

        foreach ($media as $item) {
            $this->mediaStore->delete($item->path, $item->disk);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function fields(array $attributes, ?Product $product = null): array
    {
        $fields = [];

        foreach ([
            'name', 'short_description', 'description',
            'meta_title', 'meta_description', 'meta_keywords', 'mpn',
        ] as $field) {
            if (array_key_exists($field, $attributes)) {
                $fields[$field] = $attributes[$field];
            }
        }

        if (array_key_exists('item_condition', $attributes)) {
            $fields['item_condition'] = ItemCondition::tryFrom((string) $attributes['item_condition']) ?? ItemCondition::New;
        }

        /*
         * The sharing image, by public id. Validation has already confirmed it
         * is one of this product's images; the query repeats the product check
         * so a caller that skipped validation cannot point it elsewhere.
         */
        if (array_key_exists('social_image_id', $attributes)) {
            $fields['social_media_id'] = blank($attributes['social_image_id']) || $product === null
                ? null
                : ProductMedia::query()
                    ->where('product_id', $product->id)
                    ->where('public_id', $attributes['social_image_id'])
                    ->value('id');
        }

        /*
         * Upper-case, matching the CHECK on the column: one SKU is one
         * product to a warehouse picker whatever the casing it was typed
         * in. Left blank, the BPC and the barcode are invented rather than
         * demanded — a BPC is housekeeping, not something worth blocking a
         * draft on.
         */
        if (array_key_exists('sku', $attributes)) {
            $sku = mb_strtoupper(trim((string) $attributes['sku']));
            $fallbackName = $product !== null ? $product->name : '';
            $fields['sku'] = $sku !== ''
                ? $sku
                : $this->identifiers->sku((string) ($attributes['name'] ?? $fallbackName));
        }

        if (array_key_exists('barcode', $attributes)) {
            $fields['barcode'] = blank($attributes['barcode'])
                ? $this->identifiers->barcode()
                : $attributes['barcode'];
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

        foreach (['base_cost', 'wholesale_price'] as $figure) {
            if (array_key_exists($figure, $attributes)) {
                $fields[$figure] = Money::fromDecimal((string) $attributes[$figure], Currency::base());
            }
        }

        /*
         * Order quantities (§14). A blank minimum is one unit; a blank maximum
         * is no limit.
         */
        if (array_key_exists('min_order_quantity', $attributes)) {
            $fields['min_order_quantity'] = blank($attributes['min_order_quantity'])
                ? 1
                : (int) $attributes['min_order_quantity'];
        }

        if (array_key_exists('max_order_quantity', $attributes)) {
            $fields['max_order_quantity'] = blank($attributes['max_order_quantity'])
                ? null
                : (int) $attributes['max_order_quantity'];
        }

        // Selling-price guidance for partners (§15.1). Blank is no bound, and
        // never zero — zero is a real price.
        foreach (['suggested_selling_price', 'minimum_selling_price', 'maximum_selling_price'] as $bound) {
            if (array_key_exists($bound, $attributes)) {
                $fields[$bound] = blank($attributes[$bound])
                    ? null
                    : Money::fromDecimal((string) $attributes[$bound], Currency::base());
            }
        }

        /*
         * Physical logistics and packaging (beta-critical batch, Commit 1).
         * Every figure is optional -- a blank clears it rather than failing,
         * since a product with stock but no weight yet recorded is not an
         * error state. Weight arrives already converted to whole grams and
         * every dimension already in centimetres (never a float): see
         * SaveProductRequest::productAttributes().
         */
        foreach (['net_weight_grams', 'shipping_weight_grams', 'pieces_per_box', 'box_weight_grams'] as $wholeNumber) {
            if (array_key_exists($wholeNumber, $attributes)) {
                $fields[$wholeNumber] = blank($attributes[$wholeNumber]) ? null : (int) $attributes[$wholeNumber];
            }
        }

        foreach (['length_cm', 'width_cm', 'height_cm', 'box_length_cm', 'box_width_cm', 'box_height_cm'] as $dimension) {
            if (array_key_exists($dimension, $attributes)) {
                $fields[$dimension] = blank($attributes[$dimension]) ? null : (string) $attributes[$dimension];
            }
        }

        foreach (['ships_by_box', 'is_fragile'] as $flag) {
            if (array_key_exists($flag, $attributes)) {
                $fields[$flag] = (bool) $attributes[$flag];
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
            'base_cost' => $product->base_cost->toDecimal(),
            'wholesale_price' => $product->wholesale_price->toDecimal(),
            'currency_code' => $product->currency_code,
            'min_order_quantity' => $product->min_order_quantity,
            'max_order_quantity' => $product->max_order_quantity,
            'suggested_selling_price' => $product->suggested_selling_price?->toDecimal(),
            'minimum_selling_price' => $product->minimum_selling_price?->toDecimal(),
            'maximum_selling_price' => $product->maximum_selling_price?->toDecimal(),
            'status' => $product->status->value,
            'net_weight_grams' => $product->net_weight_grams,
            'shipping_weight_grams' => $product->shipping_weight_grams,
            'length_cm' => $product->length_cm,
            'width_cm' => $product->width_cm,
            'height_cm' => $product->height_cm,
            'ships_by_box' => $product->ships_by_box,
            'pieces_per_box' => $product->pieces_per_box,
            'box_weight_grams' => $product->box_weight_grams,
            'box_length_cm' => $product->box_length_cm,
            'box_width_cm' => $product->box_width_cm,
            'box_height_cm' => $product->box_height_cm,
            'is_fragile' => $product->is_fragile,
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
