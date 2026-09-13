<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Catalog\ProductMediaStore;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Adding, describing, ordering and removing a product's images and videos
 * (§11.1).
 *
 * Every write locks the product row, so positions stay a clean 1..n sequence
 * even when two administrators upload at once, and the per-product cap is
 * counted against a number nobody else can change mid-write.
 *
 * The file and the row are kept in step. An upload is written before the
 * transaction — a rollback cannot unwrite a file — and removed if the save
 * fails; a removed row's file is deleted only after the removal commits.
 */
class ManageProductMedia
{
    /**
     * Enough for every angle and a clip or two; more than this is a gallery
     * nobody scrolls, and an unbounded list is an unbounded disk.
     */
    public const MAX_PER_PRODUCT = 30;

    public function __construct(
        protected RecordAuditLog $audit,
        protected ProductMediaStore $store,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws CatalogRefused
     */
    public function add(
        User $actor,
        Product $product,
        UploadedFile $file,
        ?string $altText = null,
        ?string $variantPublicId = null,
    ): ProductMedia {
        // Before the file is written: a refused upload leaves nothing on disk.
        CatalogPolicy::authorize(CatalogPolicy::canEdit($actor), 'You may not add product media.');

        $stored = $this->store->store($file, $product);

        try {
            return $this->database->transaction(function () use ($actor, $product, $stored, $altText, $variantPublicId) {
                /** @var Product $locked */
                $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

                if ($locked->media()->count() >= self::MAX_PER_PRODUCT) {
                    throw CatalogRefused::tooMuchMedia(self::MAX_PER_PRODUCT);
                }

                $media = ProductMedia::create([
                    ...$stored,
                    'product_id' => $locked->id,
                    'product_variant_id' => $this->variantId($locked, $variantPublicId),
                    'disk' => ProductMediaStore::DISK,
                    'alt_text' => $altText,
                    'position' => (int) $locked->media()->max('position') + 1,
                ]);

                $this->record($actor, 'catalog.media_added', $media, after: $this->snapshot($media));

                return $media;
            });
        } catch (Throwable $failure) {
            // Nothing references it, so it is ours to clean up.
            $this->store->delete($stored['path']);

            throw $failure;
        }
    }

    /**
     * Change what a file says about itself — its alt text and which variation it
     * shows. The file itself is never replaced in place; a new picture is a new
     * upload.
     *
     * @param  array{alt_text?: string|null, variant_id?: string|null}  $attributes
     *
     * @throws CatalogRefused
     */
    public function describe(User $actor, ProductMedia $media, array $attributes): ProductMedia
    {
        CatalogPolicy::authorize(CatalogPolicy::canEdit($actor), 'You may not edit product media.');

        return $this->database->transaction(function () use ($actor, $media, $attributes) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($media->product_id)->lockForUpdate()->firstOrFail();

            $before = $this->snapshot($media);

            if (array_key_exists('alt_text', $attributes)) {
                $media->alt_text = $attributes['alt_text'];
            }

            if (array_key_exists('variant_id', $attributes)) {
                $media->product_variant_id = $this->variantId($locked, $attributes['variant_id']);
            }

            $media->save();

            $this->record($actor, 'catalog.media_updated', $media, $before, $this->snapshot($media));

            return $media->refresh();
        });
    }

    /**
     * Put the product's media in a new order.
     *
     * The whole order in one write, because a reorder is one decision. Ids that
     * are not this product's are ignored rather than trusted, and anything the
     * screen did not mention keeps its relative place after the ones it did — a
     * stale screen cannot drop a file out of the sequence.
     *
     * @param  array<int, string>  $publicIds
     */
    public function reorder(User $actor, Product $product, array $publicIds): void
    {
        CatalogPolicy::authorize(CatalogPolicy::canEdit($actor), 'You may not reorder product media.');

        $this->database->transaction(function () use ($actor, $product, $publicIds) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $media = $locked->media()->get()->keyBy('public_id');

            /** @var array<int, ProductMedia> $ordered */
            $ordered = [];

            // The ones the screen named, in its order — and only this product's.
            foreach (array_unique($publicIds) as $publicId) {
                $item = $media->get($publicId);

                if ($item instanceof ProductMedia) {
                    $ordered[] = $item;
                }
            }

            // Then everything it did not mention, in the order it already had.
            foreach ($media as $item) {
                if (! in_array($item->public_id, $publicIds, true)) {
                    $ordered[] = $item;
                }
            }

            $this->renumber($ordered);

            $this->audit->handle(new AuditEntry(
                action: 'catalog.media_reordered',
                actorId: $actor->id,
                auditableType: Product::class,
                auditableId: $locked->id,
                after: ['order' => array_map(fn (ProductMedia $item) => $item->public_id, $ordered)],
                module: 'catalog',
            ));
        });
    }

    public function remove(User $actor, ProductMedia $media): void
    {
        CatalogPolicy::authorize(CatalogPolicy::canEdit($actor), 'You may not remove product media.');

        $path = $media->path;

        $this->database->transaction(function () use ($actor, $media) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($media->product_id)->lockForUpdate()->firstOrFail();

            $this->record($actor, 'catalog.media_removed', $media, before: $this->snapshot($media));

            $media->delete();

            // Close the gap, so position 1 is always the listing image.
            $this->renumber($locked->media()->get()->all());
        });

        $this->store->delete($path);
    }

    /**
     * @param  array<int, ProductMedia>  $media  in the order wanted
     */
    protected function renumber(array $media): void
    {
        foreach (array_values($media) as $index => $item) {
            if ($item->position !== $index + 1) {
                $item->forceFill(['position' => $index + 1])->save();
            }
        }
    }

    /**
     * @throws CatalogRefused
     */
    protected function variantId(Product $product, ?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        $variant = ProductVariant::query()->where('public_id', $publicId)->first(['id', 'product_id']);

        if ($variant === null || $variant->product_id !== $product->id) {
            throw CatalogRefused::variantOfAnotherProduct();
        }

        return $variant->id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(ProductMedia $media): array
    {
        return [
            'product_id' => $media->product_id,
            'type' => $media->type,
            'path' => $media->path,
            'alt_text' => $media->alt_text,
            'variant_id' => $media->product_variant_id,
            'position' => $media->position,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function record(
        User $actor,
        string $action,
        ProductMedia $media,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: ProductMedia::class,
            auditableId: $media->id,
            before: $before,
            after: $after,
            module: 'catalog',
        ));
    }
}
