<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Catalog\Actions\ManageProductMedia;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierListingMedia;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingItem;
use App\Domain\Supplier\SupplierListingMediaStore;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Adding, describing, ordering and removing a Supplier product entry's
 * images (Supplier Bulk Product Listing batch) -- mirrors
 * {@see ManageProductMedia} exactly: the file is
 * written before the transaction (a rollback cannot unwrite it) and removed
 * if the save fails; a removed row's file is deleted only after the removal
 * commits.
 *
 * Ownership is a guard concern, not a policy one, matching every other
 * Supplier-side write in this domain: the caller passes the Supplier making
 * the request, and every method refuses a listing that is not theirs or
 * that {@see SupplierProductListing::isEditableBySupplier()} says is no
 * longer editable -- the same window the database's own
 * `feriwala_supplier_listing_media_locked_after_submission` trigger
 * enforces independently.
 */
class ManageSupplierListingMedia
{
    /**
     * Primary plus gallery together; enough for a genuine product gallery
     * without an unbounded list becoming an unbounded disk.
     */
    public const MAX_PER_LISTING = 10;

    public const MAX_PER_VARIANT = 4;

    public function __construct(
        protected SupplierListingMediaStore $store,
        protected DatabaseManager $database,
    ) {}

    public function add(
        Supplier $supplier,
        SupplierProductListing $listing,
        UploadedFile $file,
        string $role,
        string $altText,
        ?SupplierProductListingItem $variant = null,
    ): SupplierListingMedia {
        $this->assertOwnedAndEditable($supplier, $listing);
        $this->assertRoleKnown($role);
        $this->assertAltTextPresent($altText);

        if ($variant !== null && $variant->supplier_product_listing_id !== $listing->id) {
            throw new InvalidArgumentException('This variation does not belong to this listing.');
        }

        // Before anything is written to the database: a refused upload
        // leaves nothing on disk.
        $stored = $this->store->store($file, $listing);

        try {
            return $this->database->transaction(function () use ($listing, $variant, $stored, $role, $altText) {
                /** @var SupplierProductListing $locked */
                $locked = SupplierProductListing::query()->lockForUpdate()->findOrFail($listing->id);

                $this->assertEditable($locked);

                $scope = $variant === null ? $locked->media() : $variant->variantMedia();
                $cap = $variant === null ? self::MAX_PER_LISTING : self::MAX_PER_VARIANT;

                if ($scope->count() >= $cap) {
                    throw new InvalidArgumentException("This listing already carries the maximum of {$cap} images here.");
                }

                if ($role === SupplierListingMedia::ROLE_PRIMARY && $variant === null && $locked->primaryMedia() !== null) {
                    throw new InvalidArgumentException('This listing already has a primary image. Remove it before adding another.');
                }

                return SupplierListingMedia::create([
                    ...$stored,
                    'supplier_product_listing_id' => $locked->id,
                    'supplier_product_listing_item_id' => $variant?->id,
                    'role' => $role,
                    'alt_text' => $altText,
                    'position' => (int) $scope->max('position') + 1,
                ]);
            });
        } catch (Throwable $failure) {
            $this->store->delete($stored['path'], $stored['disk']);

            throw $failure;
        }
    }

    /**
     * @param  array{alt_text?: string, role?: string}  $attributes
     */
    public function describe(Supplier $supplier, SupplierListingMedia $media, array $attributes): SupplierListingMedia
    {
        $listing = $media->listing;
        $this->assertOwnedAndEditable($supplier, $listing);

        if (array_key_exists('role', $attributes)) {
            $this->assertRoleKnown($attributes['role']);
        }

        if (array_key_exists('alt_text', $attributes)) {
            $this->assertAltTextPresent($attributes['alt_text']);
        }

        return DB::transaction(function () use ($listing, $media, $attributes) {
            /** @var SupplierProductListing $locked */
            $locked = SupplierProductListing::query()->lockForUpdate()->findOrFail($listing->id);
            $this->assertEditable($locked);

            $media->forceFill(array_intersect_key($attributes, array_flip(['alt_text', 'role'])))->save();

            return $media->refresh();
        });
    }

    /**
     * Put a product entry's own images in a new order. Variant-specific
     * images are reordered separately, through their own variant.
     *
     * @param  array<int, string>  $publicIds
     */
    public function reorder(Supplier $supplier, SupplierProductListing $listing, array $publicIds): void
    {
        $this->assertOwnedAndEditable($supplier, $listing);

        $this->database->transaction(function () use ($listing, $publicIds) {
            /** @var SupplierProductListing $locked */
            $locked = SupplierProductListing::query()->lockForUpdate()->findOrFail($listing->id);
            $this->assertEditable($locked);

            $media = $locked->media()->get()->keyBy('public_id');

            /** @var array<int, SupplierListingMedia> $ordered */
            $ordered = [];

            foreach (array_unique($publicIds) as $publicId) {
                $item = $media->get($publicId);

                if ($item instanceof SupplierListingMedia) {
                    $ordered[] = $item;
                }
            }

            foreach ($media as $item) {
                if (! in_array($item->public_id, $publicIds, true)) {
                    $ordered[] = $item;
                }
            }

            $this->renumber($ordered);
        });
    }

    public function remove(Supplier $supplier, SupplierListingMedia $media): void
    {
        $listing = $media->listing;
        $this->assertOwnedAndEditable($supplier, $listing);

        $path = $media->path;
        $disk = $media->disk;
        $variantId = $media->supplier_product_listing_item_id;

        $this->database->transaction(function () use ($listing, $media, $variantId) {
            /** @var SupplierProductListing $locked */
            $locked = SupplierProductListing::query()->lockForUpdate()->findOrFail($listing->id);
            $this->assertEditable($locked);

            $media->delete();

            $scope = $variantId === null
                ? $locked->media()->get()->all()
                : SupplierProductListingItem::query()->findOrFail($variantId)->variantMedia()->get()->all();

            $this->renumber($scope);
        });

        $this->store->delete($path, $disk);
    }

    protected function assertOwnedAndEditable(Supplier $supplier, SupplierProductListing $listing): void
    {
        if ($listing->supplier_id !== $supplier->id) {
            throw new InvalidArgumentException('This listing does not belong to this Supplier.');
        }

        $this->assertEditable($listing);
    }

    protected function assertEditable(SupplierProductListing $listing): void
    {
        if (! $listing->isEditableBySupplier()) {
            throw new InvalidArgumentException('This listing can no longer be edited.');
        }
    }

    protected function assertRoleKnown(string $role): void
    {
        if (! in_array($role, [SupplierListingMedia::ROLE_PRIMARY, SupplierListingMedia::ROLE_GALLERY], true)) {
            throw new InvalidArgumentException("Unknown image role [{$role}].");
        }
    }

    protected function assertAltTextPresent(string $altText): void
    {
        if (trim($altText) === '') {
            throw new InvalidArgumentException('Every image needs alt text.');
        }
    }

    /**
     * @param  array<int, SupplierListingMedia>  $media  in the order wanted
     */
    protected function renumber(array $media): void
    {
        foreach (array_values($media) as $index => $item) {
            if ($item->position !== $index + 1) {
                $item->forceFill(['position' => $index + 1])->save();
            }
        }
    }
}
