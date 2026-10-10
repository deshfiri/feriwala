<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Actions\ManageVariants;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Sourcing\Actions\ManageProductLinks;
use App\Domain\Sourcing\Exceptions\ProductLinkRefused;
use App\Domain\Sourcing\Models\ProductLink;
use App\Domain\Supplier\Enums\ListingItemStatus;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierOfferStock;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingItem;
use App\Domain\Supplier\Models\SupplierStockMovement;
use App\Models\User;
use App\Notifications\Supplier\SupplierListingApproved;
use App\Notifications\Supplier\SupplierListingRejected;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Decides a Supplier's Product Listing Request: connects (or creates) the
 * Central Product it proposes, decides each variation independently, and
 * prices every approved one as a new Supplier Offer (D25, P13-11, P13-13,
 * P13-14).
 *
 * **Never duplicates catalogue logic.** Creating the product goes through the
 * existing {@see ManageProducts::create()} — the same write path §11.1/§12
 * already governs — and connecting a variation only ever *selects* an
 * existing {@see ProductVariant}; this action does not create one. A listing
 * whose proposal needs a genuinely new variation is connected once staff has
 * added that variation through the ordinary catalogue screens.
 *
 * A second Supplier approved against a product or variation a first Supplier
 * already supplies gets its **own** new {@see SupplierOffer} row — this never
 * updates another Supplier's offer, whatever the two agree to sell it for.
 */
class DecideSupplierListing
{
    public function __construct(
        protected ManageProducts $products,
        protected ManageVariants $variants,
        protected ManageProductLinks $links,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, mixed>  $productDecision  {connect_product_id?: string, create_product?: bool, category_id?: string, brand_id?: string, sku?: string, description?: string, link_product_ids?: list<string>}
     * @param  list<array<string, mixed>>  $itemDecisions  {item_id: string, decision: 'approve'|'reject'|'correction', variant_id?: string, platform_rate?: Money, approved_quantity?: int|null, wholesale_enabled?: bool, dropshipping_enabled?: bool, note?: string, logistics?: array<string, mixed>}
     */
    public function handle(
        SupplierProductListing $listing,
        User $reviewer,
        array $productDecision,
        array $itemDecisions,
        string $reason,
    ): SupplierProductListing {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required and is recorded against this decision.');
        }

        $hasApproval = collect($itemDecisions)->contains(fn (array $item) => $item['decision'] === 'approve');

        $listing = $this->database->transaction(function () use ($listing, $reviewer, $productDecision, $itemDecisions, $reason, $hasApproval) {            /** @var SupplierProductListing $locked */
            $locked = SupplierProductListing::query()->lockForUpdate()->findOrFail($listing->id);

            if ($locked->status !== ListingStatus::UnderReview) {
                throw new InvalidArgumentException('This listing is not awaiting a decision.');
            }

            $items = $locked->items()->get()->keyBy('public_id');

            $product = $hasApproval ? $this->resolveProduct($locked, $reviewer, $productDecision) : null;

            // Optional, and only what staff picked: the Product may equally
            // stay unique. Never a Supplier's choice, never guessed.
            if ($product !== null) {
                $this->linkSameProducts($product, $productDecision['link_product_ids'] ?? [], $reviewer, $reason);
            }

            foreach ($itemDecisions as $itemDecision) {
                /** @var SupplierProductListingItem|null $item */
                $item = $items->get($itemDecision['item_id']);

                if ($item === null) {
                    throw new InvalidArgumentException("Listing item [{$itemDecision['item_id']}] does not belong to this listing.");
                }

                if ($item->status !== ListingItemStatus::Pending) {
                    throw new InvalidArgumentException("Listing item [{$itemDecision['item_id']}] has already been decided.");
                }

                match ($itemDecision['decision']) {
                    'approve' => $this->approveItem($item, $product, $itemDecision, $reviewer, $reason),
                    'reject' => $item->forceFill(['status' => ListingItemStatus::Rejected, 'decision_note' => $itemDecision['note'] ?? null])->save(),
                    'correction' => $item->forceFill(['status' => ListingItemStatus::CorrectionRequired, 'decision_note' => $itemDecision['note'] ?? null])->save(),
                    default => throw new InvalidArgumentException("Unknown item decision [{$itemDecision['decision']}]."),
                };
            }

            // Rolled up from every item's current status, not only this
            // batch — a resubmission may decide only the items a previous
            // round sent back for correction, and the ones already approved
            // or rejected still count toward what the listing as a whole is.
            $counts = ['approved' => 0, 'rejected' => 0, 'correction' => 0];

            foreach ($locked->items()->get() as $item) {
                match ($item->status) {
                    ListingItemStatus::Approved => $counts['approved']++,
                    ListingItemStatus::Rejected => $counts['rejected']++,
                    ListingItemStatus::CorrectionRequired => $counts['correction']++,
                    ListingItemStatus::Pending => null,
                };
            }

            $newStatus = match (true) {
                $counts['approved'] > 0 && $counts['rejected'] === 0 && $counts['correction'] === 0 => ListingStatus::Approved,
                $counts['approved'] > 0 => ListingStatus::PartiallyApproved,
                $counts['correction'] > 0 => ListingStatus::CorrectionRequired,
                default => ListingStatus::Rejected,
            };

            $locked->transitionWithHistory(
                $newStatus,
                new StatusChange(actorId: $reviewer->id, reason: $reason),
                ['source' => SupplierStatusChangeSource::Staff],
            );
            $locked->forceFill([
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->id,
                'connected_product_id' => $product->id ?? $locked->connected_product_id,
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'supplier_listing.'.$newStatus->value,
                actorId: $reviewer->id,
                auditableType: SupplierProductListing::class,
                auditableId: $locked->id,
                after: ['status' => $newStatus->value, 'counts' => $counts],
                reason: $reason,
                module: PermissionModule::SupplierListing->value,
            ));

            return $locked;
        });

        $listing = $listing->refresh();
        $supplier = $listing->supplier;

        $notification = in_array($listing->status, [ListingStatus::Approved, ListingStatus::PartiallyApproved], true)
            ? new SupplierListingApproved($listing)
            : new SupplierListingRejected($listing);

        $supplier->notify($notification->locale($supplier->locale));

        return $listing;
    }

    /**
     * @param  array<string, mixed>  $productDecision
     */
    protected function resolveProduct(SupplierProductListing $listing, User $reviewer, array $productDecision): Product
    {
        // A listing is connected to one product, once. A later round on the
        // same listing (after a correction) keeps that connection rather than
        // being able to point it somewhere else.
        if ($listing->connected_product_id !== null) {
            return $listing->connectedProduct()->firstOrFail();
        }

        if (filled($productDecision['connect_product_id'] ?? null)) {
            return Product::query()->where('public_id', $productDecision['connect_product_id'])->firstOrFail();
        }

        if (($productDecision['create_product'] ?? false) === true) {
            // A product must sit in a category. Staff's choice wins; failing
            // that, the category the Supplier picked from the real catalogue.
            $category = $productDecision['category_id'] ?? $listing->category?->public_id;

            if (blank($category) || blank($productDecision['sku'] ?? null)) {
                throw new InvalidArgumentException('Creating a product needs a BPC and a category.');
            }

            return $this->products->create($reviewer, [
                'name' => $productDecision['name'] ?? $listing->product_name,
                'description' => $productDecision['description'] ?? $listing->description,
                'sku' => $productDecision['sku'],
                'category_id' => $category,
                'brand_id' => $productDecision['brand_id'] ?? $listing->brand?->public_id,
            ]);
        }

        throw new InvalidArgumentException('Approving an item requires connecting to an existing product or creating a new one.');
    }

    /**
     * Confirm, one by one, the existing Products staff said are the same
     * physical Product as the one this listing is connected to.
     *
     * Optional: a listing may be approved while the Product stays unique, and
     * nothing is ever linked on a guess — only what the reviewer picked. A pair
     * already linked is left as it is. Variations are not matched here: a
     * linked Product's variations stay unmatched (and so are never offered as
     * substitutes) until staff match them from the Product's Linked Products
     * section.
     *
     * @param  list<string>  $linkProductIds  public ids of the Products confirmed as the same
     */
    protected function linkSameProducts(Product $product, array $linkProductIds, User $reviewer, string $reason): void
    {
        foreach (array_values(array_unique($linkProductIds)) as $publicId) {
            $other = Product::query()->where('public_id', $publicId)->first()
                ?? throw new InvalidArgumentException('One of the Products chosen to link with does not exist.');

            if ($other->is($product)) {
                throw new InvalidArgumentException('A Product cannot be linked to itself.');
            }

            if ($this->alreadyDirectlyLinked($product, $other)) {
                continue;
            }

            try {
                $this->links->link($reviewer, $product, $other, $reason);
            } catch (AuthorizationException $exception) {
                throw new InvalidArgumentException('You may not link Products.', previous: $exception);
            } catch (ProductLinkRefused $exception) {
                throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
            }
        }
    }

    protected function alreadyDirectlyLinked(Product $product, Product $other): bool
    {
        return ProductLink::query()->active()
            ->where('product_a_id', min($product->id, $other->id))
            ->where('product_b_id', max($product->id, $other->id))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $itemDecision
     */
    protected function approveItem(SupplierProductListingItem $item, ?Product $product, array $itemDecision, User $reviewer, string $reason): void
    {
        if ($product === null) {
            throw new InvalidArgumentException('No product was resolved to connect this item to.');
        }

        $variant = filled($itemDecision['variant_id'] ?? null)
            ? ProductVariant::query()->where('public_id', $itemDecision['variant_id'])->where('product_id', $product->id)->firstOrFail()
            : null;

        if (! isset($itemDecision['platform_rate'])) {
            throw new InvalidArgumentException('A Platform Rate is required to approve a listing item.');
        }

        $currency = Currency::from($item->currency_code);
        $supplierRate = $item->supplier_rate;
        $platformRate = $itemDecision['platform_rate'] instanceof Money
            ? $itemDecision['platform_rate']
            : Money::fromDecimal((string) $itemDecision['platform_rate'], $currency);

        if ($platformRate->lessThan($supplierRate)) {
            throw new InvalidArgumentException('The Platform Rate cannot be lower than the Supplier Rate.');
        }

        $offer = SupplierOffer::create([
            'supplier_id' => $item->listing->supplier_id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'originating_listing_item_id' => $item->id,
            'status' => OfferStatus::Active,
            'wholesale_enabled' => (bool) ($itemDecision['wholesale_enabled'] ?? false),
            'dropshipping_enabled' => (bool) ($itemDecision['dropshipping_enabled'] ?? false),
            'supplier_rate' => $supplierRate,
            'platform_rate' => $platformRate,
            'currency_code' => $currency->value,
            // Copied once, at approval, from what the Supplier declared --
            // never re-entered by staff (Supplier Bulk Product Listing batch).
            'supply_mode' => $item->supply_mode,
            'lead_time_days' => $item->lead_time_days,
            'fulfilment_capacity' => $item->fulfilment_capacity,
            'expected_availability_at' => $item->expected_availability_at,
            'activated_by' => $reviewer->id,
            'activated_at' => now(),
        ]);

        $offer->priceHistory()->create([
            'supplier_rate' => $supplierRate,
            'platform_rate' => $platformRate,
            'currency_code' => $currency->value,
            'effective_from' => now(),
            'changed_by' => $reviewer->id,
            'reason' => 'Initial pricing set at listing approval.',
            'created_at' => now(),
        ]);

        // Ready stock only opens a real stock row when a quantity actually
        // exists; on_demand/pre_order never get one -- there is no physical
        // stock to open, and a zero-quantity row would read as "confirmed no
        // stock" rather than "not applicable" (Supplier Bulk Product Listing
        // batch, corrections 5/6).
        $approvedQuantity = $this->approvedQuantity($item, $itemDecision);

        if ($item->supply_mode === SupplyMode::ReadyStock && $approvedQuantity !== null) {
            $this->openStock($offer, $item, $approvedQuantity, $reviewer);
        }

        $item->forceFill([
            'status' => ListingItemStatus::Approved,
            'connected_product_variant_id' => $variant?->id,
            'supplier_offer_id' => $offer->id,
            'decision_note' => $itemDecision['note'] ?? null,
        ])->save();

        $this->confirmLogistics($product, $variant, $itemDecision, $reviewer);
    }

    /**
     * Write a reviewer's own confirmed (or corrected) logistics figures onto
     * the central Product or Variant this item connects to (beta-critical
     * batch, Commit 1).
     *
     * The Supplier's own proposal ({@see SupplierProductListingItem::
     * proposedLogistics()}) never reaches the catalogue by itself — approving
     * an item is not, on its own, a logistics decision. Only an explicit
     * `logistics` key in this item's decision is written, through the same
     * `ManageProducts`/`ManageVariants` write path every other catalogue edit
     * uses, so it is validated, audited and locked-column-guarded exactly
     * the same way a staff member editing the product directly would be.
     * Approving without one leaves the central record's logistics exactly as
     * they were.
     *
     * @param  array<string, mixed>  $itemDecision
     */
    protected function confirmLogistics(Product $product, ?ProductVariant $variant, array $itemDecision, User $reviewer): void
    {
        if (! isset($itemDecision['logistics']) || ! is_array($itemDecision['logistics'])) {
            return;
        }

        if ($variant !== null) {
            $this->variants->update($reviewer, $variant, $itemDecision['logistics']);

            return;
        }

        $this->products->update($reviewer, $product, $itemDecision['logistics']);
    }

    /**
     * The offer's opening availability, taken from the quantity the Supplier
     * said they could supply — or the quantity staff approved instead.
     *
     * The Supplier already told us this on the listing item, so asking them to
     * type the same figure again after approval was work for nothing. Staff may
     * approve a different number; the difference is visible in the movement's
     * own reason rather than by overwriting what the Supplier asked for, which
     * stays on the listing item untouched.
     *
     * Keyed so a retried decision cannot open the same offer's stock twice. The
     * offer itself is already unique per listing item, so this is the backstop
     * rather than the guard.
     */
    protected function openStock(
        SupplierOffer $offer,
        SupplierProductListingItem $item,
        int $approved,
        User $reviewer,
    ): void {
        if ($approved < 0) {
            throw new InvalidArgumentException('Approved availability cannot be negative.');
        }

        $requested = $item->available_quantity;

        /** @var SupplierOfferStock $stock */
        $stock = $offer->stock()->create(['quantity' => $approved]);

        $buckets = $stock->buckets();

        SupplierStockMovement::create([
            'supplier_offer_id' => $offer->id,
            'quantity_before' => 0,
            'quantity_after' => $approved,
            'moved_quantity' => $approved > 0 ? $approved : null,
            'to_bucket' => $approved > 0 ? StockBucket::Available->value : null,
            'buckets_before' => array_fill_keys(array_keys($buckets), 0),
            'buckets_after' => $buckets,
            'source' => 'initial',
            'actor_type' => 'staff',
            'actor_id' => $reviewer->id,
            'reason' => match (true) {
                $requested === null => "Opening availability approved at {$approved}; the Supplier did not declare a quantity.",
                $approved === $requested => 'Opening availability from the approved listing item.',
                default => "Opening availability approved at {$approved}; the Supplier asked to supply {$requested}.",
            },
            'idempotency_key' => 'supplier-offer-opening:'.$item->public_id,
            'created_at' => now(),
        ]);
    }

    /**
     * The quantity to open stock at -- staff's own figure when given, else
     * whatever the Supplier declared, else `null` (nothing to open at all).
     *
     * @param  array<string, mixed>  $itemDecision
     */
    protected function approvedQuantity(SupplierProductListingItem $item, array $itemDecision): ?int
    {
        if (array_key_exists('approved_quantity', $itemDecision) && $itemDecision['approved_quantity'] !== null) {
            return (int) $itemDecision['approved_quantity'];
        }

        return $item->available_quantity === null ? null : (int) $item->available_quantity;
    }
}
