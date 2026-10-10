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
use App\Domain\Catalog\ProductDeletionRule;
use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\ProductSourceLink;
use App\Domain\Sourcing\Models\ProductLink;
use App\Domain\Sourcing\Models\ProductSourcingGroupProduct;
use App\Domain\Sourcing\Models\ProductSourcingVariantMapping;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Wholesale\Models\CartItem;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

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
        protected ProductDeletionRule $deletionRule,
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
     * Take a product out of circulation, in any status the administrator's
     * deletion setting allows ({@see ProductDeletionRule}).
     *
     * Trash is reversible and touches nothing but this one row: the product
     * disappears from every admin list, partner catalogue, storefront, cart and
     * allocation lookup immediately (every one of them reads through
     * `Product::query()`, and the soft-delete scope is Eloquent's own), while
     * every table that actually names it — status history, stock, supplier
     * offers, sourcing, orders — is left completely alone. A reason is always
     * recorded: somebody will eventually ask why a product vanished.
     *
     * @throws CatalogRefused
     */
    public function trash(User $actor, Product $product, string $reason): void
    {
        CatalogPolicy::authorize(CatalogPolicy::canDelete($actor), 'You may not delete products.');

        $this->database->transaction(function () use ($actor, $product, $reason) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (! $this->deletionRule->allows($locked->status)) {
                throw CatalogRefused::productNotDeletableInStatus($locked->status->value);
            }

            $locked->forceFill([
                'deleted_by' => $actor->id,
                'deletion_reason' => $reason,
            ])->save();

            $this->record($actor, 'catalog.product_trashed', $locked, reason: $reason, before: $this->snapshot($locked));

            $locked->delete();
        });
    }

    /**
     * Bring a trashed product back exactly as it was.
     *
     * Clears who trashed it and why along with the soft-delete itself, so a
     * product restored and trashed again later starts that record fresh.
     *
     * @throws CatalogRefused
     */
    public function restore(User $actor, Product $product): void
    {
        CatalogPolicy::authorize(CatalogPolicy::canDelete($actor), 'You may not delete products.');

        $this->database->transaction(function () use ($actor, $product) {
            /** @var Product $locked */
            $locked = Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (! $locked->trashed()) {
                throw CatalogRefused::productNotTrashed();
            }

            $this->record($actor, 'catalog.product_restored', $locked, before: [
                'deletion_reason' => $locked->deletion_reason,
            ]);

            $locked->forceFill(['deleted_by' => null, 'deletion_reason' => null]);
            $locked->restore();
        });
    }

    /**
     * Erase a trashed Draft product outright — only once nothing real has ever
     * used it.
     *
     * The product must currently be Draft, and every operational or financial
     * reference is checked before anything is touched: an order line, a supplier
     * offer or listing, a stock item or movement, a cart, a storefront listing,
     * a sourcing group or source link. Only once every one comes back empty does
     * this remove the product's own draft-only rows — media, price tiers,
     * variants, pivots — and the product itself.
     *
     * Its status history is never deleted. Each row is stamped with the
     * product's public id, SKU, name and status, then detached by the foreign
     * key's `ON DELETE SET NULL`, so it survives as a tombstone. The append-only
     * trigger permits exactly those two moves and nothing else. The final
     * `forceDelete()` is still wrapped in case a reference this method did not
     * think to name exists; the database's own constraints are the backstop,
     * never routed around.
     *
     * @throws CatalogRefused
     */
    public function permanentlyDelete(User $actor, Product $product): void
    {
        CatalogPolicy::authorize(CatalogPolicy::canDelete($actor), 'You may not delete products.');

        $media = collect();

        $this->database->transaction(function () use ($actor, $product, &$media) {
            /** @var Product $locked */
            $locked = Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (! $locked->trashed()) {
                throw CatalogRefused::productNotTrashed();
            }

            $this->assertPermanentlyDeletable($locked);

            $media = ProductMedia::query()->where('product_id', $locked->id)->get(['path', 'disk']);

            $historyRows = $this->database->table('product_status_history')->where('product_id', $locked->id)->count();

            $this->record($actor, 'catalog.product_permanently_deleted', $locked, before: [
                ...$this->snapshot($locked),
                'public_id' => $locked->public_id,
                'status_history_rows_kept' => $historyRows,
                'deletion_reason' => $locked->deletion_reason,
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

            // Tombstone: rows written before the snapshot columns existed are
            // stamped now, through the one UPDATE the append-only trigger allows,
            // so the foreign key can detach them without losing which product
            // they were about.
            $this->database->table('product_status_history')
                ->where('product_id', $locked->id)
                ->whereNull('product_public_id')
                ->update([
                    'product_public_id' => $locked->public_id,
                    'product_sku' => $locked->sku,
                    'product_name' => $locked->name,
                    'product_status' => $locked->status->value,
                ]);

            try {
                $locked->forceDelete();
            } catch (QueryException $exception) {
                throw CatalogRefused::productHasBusinessHistory('it is still referenced elsewhere in the system');
            }
        });

        foreach ($media as $item) {
            $this->mediaStore->delete($item->path, $item->disk);
        }
    }

    /**
     * Refuse outright unless this product is genuinely unused — never
     * cascade-deleted, never worked around (§6, §7, §8 of the Trash batch).
     *
     * @throws CatalogRefused
     */
    protected function assertPermanentlyDeletable(Product $locked): void
    {
        // Status history does not block: every product has some from creation,
        // and it is kept as a tombstone rather than deleted.
        if ($locked->status !== ProductStatus::Draft) {
            throw CatalogRefused::productNotDraft($locked->status->value);
        }

        $variantIds = ProductVariant::query()->where('product_id', $locked->id)->pluck('id');

        if ($locked->contents()->exists()) {
            throw CatalogRefused::productHasBusinessHistory('it has published updates');
        }

        if (
            $this->database->table('stock_movements')
                ->where('product_id', $locked->id)
                ->orWhereIn('product_variant_id', $variantIds)
                ->exists()
        ) {
            throw CatalogRefused::productHasBusinessHistory('stock movements refer to it');
        }

        if (
            $this->database->table('supplier_product_listings')->where('connected_product_id', $locked->id)->exists()
            || $this->database->table('supplier_product_listing_items')->whereIn('connected_product_variant_id', $variantIds)->exists()
        ) {
            throw CatalogRefused::productHasBusinessHistory('a supplier listing is connected to it');
        }

        if (
            StockItem::query()->where('product_id', $locked->id)->orWhereIn('product_variant_id', $variantIds)->exists()
        ) {
            throw CatalogRefused::productHasBusinessHistory('it has warehouse stock recorded against it');
        }

        if (
            SupplierOffer::query()->where('product_id', $locked->id)->orWhereIn('product_variant_id', $variantIds)->exists()
        ) {
            throw CatalogRefused::productHasBusinessHistory('a supplier has offered it');
        }

        if (
            $this->database->table('product_sourcing_variant_mappings')
                ->whereIn('canonical_product_variant_id', $variantIds)
                ->orWhereIn('product_variant_id', $variantIds)
                ->exists()
        ) {
            throw CatalogRefused::productHasBusinessHistory('it belongs to a product sourcing group');
        }

        if (
            OrderItem::query()->where('product_id', $locked->id)
                ->orWhereIn('product_variant_id', $variantIds)
                ->orWhere('sourcing_canonical_product_id', $locked->id)
                ->orWhereIn('sourcing_canonical_variant_id', $variantIds)
                ->exists()
        ) {
            throw CatalogRefused::productHasBusinessHistory('orders refer to it');
        }

        if (CartItem::query()->where('product_id', $locked->id)->exists()) {
            throw CatalogRefused::productHasBusinessHistory('a cart still holds it');
        }

        if (WebsiteProduct::query()->where('product_id', $locked->id)->exists()) {
            throw CatalogRefused::productHasBusinessHistory('it is published on a partner storefront');
        }

        if (
            ProductSourcingGroupProduct::query()->where('product_id', $locked->id)->exists()
            || ProductSourcingVariantMapping::query()->where('product_id', $locked->id)->exists()
        ) {
            throw CatalogRefused::productHasBusinessHistory('it belongs to a product sourcing group');
        }

        // A link row, even an unlinked one, is the record of a staff decision
        // and is never deleted.
        if (ProductLink::query()->touching($locked->id)->exists()) {
            throw CatalogRefused::productHasBusinessHistory('it has been linked as the same Product as another');
        }

        if (
            ProductSourceLink::query()
                ->where('ordered_product_id', $locked->id)
                ->orWhereIn('ordered_product_variant_id', $variantIds)
                ->exists()
        ) {
            throw CatalogRefused::productHasBusinessHistory("it is linked as another product's fulfilment source");
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
            $fields['sku'] = $sku !== '' ? $sku : $this->identifiers->sku();
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
        ?string $reason = null,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: Product::class,
            auditableId: $product->id,
            before: $before,
            after: $after,
            reason: $reason,
            module: 'catalog',
        ));
    }
}
