<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Catalog\ProductForceDeletion;
use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Actions\ReleaseAllocatedStock;
use App\Domain\Inventory\Enums\StockAdjustmentKind;
use App\Domain\Inventory\Models\StockAllocation;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\StockReservations;
use App\Domain\Supplier\Actions\SuspendSupplierOffer;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Super Admin Force Delete: erase a trashed Product that has history behind it.
 *
 * The normal permanent delete ({@see ManageProducts::permanentlyDelete()}) is
 * for a Product nothing ever used. This is for the test or unwanted Product
 * that was, and it works by taking the Product out of every live place first
 * and then removing it, while everything that merely *records* what happened
 * stays and is read from its own snapshot (see the migration that added them).
 *
 * Inside one transaction, with the Product row locked:
 *  - storefront selections are unpublished (and removed when no order used
 *    them), carts drop it, Supplier listings are archived and offers suspended;
 *  - product links, sourcing memberships and variant mappings are deactivated;
 *  - only active, uncommitted reservations are released; physical stock is
 *    zeroed through {@see AdjustStock} so each unit leaves with a movement;
 *  - stock items nothing was ever recorded against are removed, the rest stay,
 *    emptied and detached;
 *  - one sensitive audit entry stores who, why, the Product snapshot, and what
 *    was touched.
 *
 * Refused while work is still in flight (an unfinished order, stock committed to
 * one, an active order allocation): that is not history, it is somebody's order.
 * The database's draft-only delete guard is released for this one Product, in
 * this one transaction, through `feriwala.force_delete_product_id`.
 */
class ForceDeleteProduct
{
    public const MINIMUM_REASON = 10;

    public function __construct(
        protected DatabaseManager $database,
        protected RecordAuditLog $audit,
        protected ProductForceDeletion $deletion,
        protected ProductMediaStore $mediaStore,
        protected AdjustStock $adjustStock,
        protected ReleaseAllocatedStock $releaseAllocated,
        protected StockReservations $reservations,
        protected SuspendSupplierOffer $suspendOffer,
    ) {}

    /**
     * @param  string  $confirmation  the Product's SKU (its BPC) or its exact name
     *
     * @throws CatalogRefused
     */
    public function handle(User $actor, Product $product, string $reason, string $confirmation): void
    {
        CatalogPolicy::authorize(CatalogPolicy::canForceDelete($actor), 'Only a Super Admin may force delete a Product.');

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MINIMUM_REASON) {
            throw CatalogRefused::forceDeleteNeedsReason(self::MINIMUM_REASON);
        }

        $media = collect();

        $summary = $this->database->transaction(function () use ($actor, $product, $reason, $confirmation, &$media) {
            /** @var Product $locked */
            $locked = Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (! $locked->trashed()) {
                throw CatalogRefused::productNotTrashed();
            }

            if ($confirmation !== $locked->sku && $confirmation !== $locked->name) {
                throw CatalogRefused::forceDeleteConfirmationMismatch();
            }

            $impact = $this->deletion->impact($locked);

            if ($impact['blockers'] !== []) {
                throw CatalogRefused::forceDeleteBlocked($impact['blockers']);
            }

            $snapshot = [
                'public_id' => $locked->public_id,
                'sku' => $locked->sku,
                'name' => $locked->name,
                'status' => $locked->status->value,
                'category_id' => $locked->category_id,
            ];

            $note = "Force delete of {$locked->sku}: {$reason}";
            $variantIds = $this->database->table('product_variants')->where('product_id', $locked->id)->pluck('id');
            $items = StockItem::query()
                ->where('product_id', $locked->id)
                ->orWhereIn('product_variant_id', $variantIds)
                ->lockForUpdate()
                ->get();
            $offers = SupplierOffer::query()->where('product_id', $locked->id)->lockForUpdate()->get();

            $this->releaseReservations($actor, $items->pluck('id'), $offers->pluck('id'), $note);
            $this->zeroStock($actor, $items, $note);
            $this->deactivateSupplierSide($actor, $locked, $offers, $note);
            $this->deactivateLinks($actor, $locked, $variantIds, $note);
            $this->removeStorefrontAndCarts($locked);

            $this->database->table('stock_items')
                ->whereIn('id', $this->deletion->unusedStockItems($items->pluck('id'))->pluck('id'))
                ->delete();

            $media = $this->database->table('product_media')->where('product_id', $locked->id)->get(['path', 'disk']);

            foreach (['product_media', 'product_price_tiers', 'product_package_eligibility', 'product_user_eligibility'] as $table) {
                $this->database->table($table)->where('product_id', $locked->id)->delete();
            }

            $this->database->table('product_related')
                ->where('product_id', $locked->id)
                ->orWhere('related_product_id', $locked->id)
                ->delete();

            // Variations first: an offer's variation must match its product at every step.
            $this->database->table('product_variants')->where('product_id', $locked->id)->delete();

            // Legacy history rows are stamped before the foreign key detaches them.
            $this->database->table('product_status_history')
                ->where('product_id', $locked->id)
                ->whereNull('product_public_id')
                ->update([
                    'product_public_id' => $locked->public_id,
                    'product_sku' => $locked->sku,
                    'product_name' => $locked->name,
                    'product_status' => $locked->status->value,
                ]);

            $this->audit->handle(new AuditEntry(
                action: 'catalog.product_force_deleted',
                actorId: $actor->id,
                auditableType: Product::class,
                auditableId: $locked->id,
                before: $snapshot,
                after: ['removed' => $impact['removed'], 'deactivated' => $impact['deactivated'], 'preserved' => $impact['preserved']],
                reason: $reason,
                module: 'catalog',
                isSensitive: true,
            ));

            $this->database->statement("SELECT set_config('feriwala.force_delete_product_id', ?, true)", [(string) $locked->id]);

            try {
                $locked->forceDelete();
            } catch (QueryException $exception) {
                throw CatalogRefused::productHasBusinessHistory('a record that could not be detached still refers to it');
            }

            return $impact;
        });

        foreach ($media as $item) {
            $this->mediaStore->delete($item->path, $item->disk);
        }

        unset($summary);
    }

    /**
     * Give back every active reservation, central and Supplier. Committed ones
     * are not safe to release and are never touched.
     *
     * @param  Collection<int, mixed>  $itemIds
     * @param  Collection<int, mixed>  $offerIds
     */
    protected function releaseReservations(User $actor, $itemIds, $offerIds, string $note): void
    {
        $ids = $this->deletion->activeReservations($itemIds, $offerIds)->pluck('id');

        foreach (StockReservation::query()->whereIn('id', $ids)->get() as $reservation) {
            $this->reservations->release($reservation, $note, $actor->id);
        }
    }

    /**
     * Bring every physical bucket to zero through the stock service, so each
     * unit that leaves is a recorded movement and adjustment.
     *
     * @param  Collection<int, StockItem>  $items
     */
    protected function zeroStock(User $actor, $items, string $note): void
    {
        foreach ($items as $staleItem) {
            foreach (StockAllocation::query()->where('stock_item_id', $staleItem->id)->where('quantity', '>', 0)->get() as $allocation) {
                $this->releaseAllocated->handle($actor, $allocation, $allocation->quantity, $note);
            }

            $steps = [
                ['returned', StockAdjustmentKind::RestockReturn],
                ['available', StockAdjustmentKind::Remove],
                ['damaged', StockAdjustmentKind::WriteOffDamaged],
            ];

            foreach ($steps as [$bucket, $kind]) {
                $item = StockItem::query()->findOrFail($staleItem->id);
                $quantity = (int) $item->getAttribute($bucket);

                if ($quantity > 0) {
                    $this->adjustStock->handle($actor, $item, $kind, $quantity, $note);
                }
            }
        }
    }

    /**
     * @param  Collection<int, SupplierOffer>  $offers
     */
    protected function deactivateSupplierSide(User $actor, Product $product, $offers, string $note): void
    {
        foreach ($offers as $offer) {
            if ($offer->status->value === 'active') {
                $this->suspendOffer->handle($offer, $actor->id, $note);
            }
        }

        $listings = SupplierProductListing::query()->where('connected_product_id', $product->id)->get();

        foreach ($listings as $listing) {
            if ($listing->canTransitionTo(ListingStatus::Archived)) {
                $listing->transitionWithHistory(
                    ListingStatus::Archived,
                    new StatusChange(reason: $note),
                    ['source' => SupplierStatusChangeSource::Staff],
                );
            }
        }
    }

    /**
     * Links, sourcing memberships and variant mappings stay as rows — their
     * guards forbid deleting them — but stop being active.
     *
     * @param  Collection<int, mixed>  $variantIds
     */
    protected function deactivateLinks(User $actor, Product $product, $variantIds, string $note): void
    {
        $now = now();
        $removed = ['status' => 'removed', 'removed_by' => $actor->id, 'removed_at' => $now, 'removal_reason' => $note];

        $linkIds = $this->database->table('product_same_links')
            ->where('status', 'active')
            ->where(fn ($query) => $query->where('product_a_id', $product->id)->orWhere('product_b_id', $product->id))
            ->pluck('id');

        $this->database->table('product_link_variant_mappings')
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereIn('product_link_id', $linkIds)
                ->orWhereIn('variant_a_id', $variantIds)->orWhereIn('variant_b_id', $variantIds))
            ->update($removed);

        $this->database->table('product_same_links')->whereIn('id', $linkIds)->update([
            'status' => 'unlinked', 'unlinked_by' => $actor->id, 'unlinked_at' => $now, 'unlink_reason' => $note,
        ]);

        $this->database->table('product_sourcing_variant_mappings')
            ->where('status', 'active')
            ->where(fn ($query) => $query->where('product_id', $product->id)
                ->orWhereIn('canonical_product_variant_id', $variantIds))
            ->update($removed);

        $this->database->table('product_sourcing_group_products')
            ->where('status', 'active')
            ->where('product_id', $product->id)
            ->update($removed);

        $this->database->table('product_source_links')
            ->where('status', 'active')
            ->where('ordered_product_id', $product->id)
            ->update(['status' => 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => $now, 'revocation_reason' => $note]);
    }

    /**
     * Off every storefront and out of every cart. A selection an order used is
     * kept, unpublished, because the order names it.
     */
    protected function removeStorefrontAndCarts(Product $product): void
    {
        $this->database->table('cart_items')->where('product_id', $product->id)->delete();

        $this->database->table('website_products')->where('product_id', $product->id)->update([
            'status' => 'unpublished',
            'unpublished_at' => now(),
        ]);

        $this->database->table('website_products')
            ->where('product_id', $product->id)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('order_items')->whereColumn('order_items.website_product_id', 'website_products.id'))
            ->delete();
    }
}
