<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Supplier\Enums\ListingItemStatus;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingItem;
use App\Domain\Supplier\Models\SupplierStockMovement;
use App\Models\User;
use App\Notifications\Supplier\SupplierListingApproved;
use App\Notifications\Supplier\SupplierListingRejected;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
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
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, mixed>  $productDecision  {connect_product_id?: string, create_product?: bool, category_id?: string, brand_id?: string, sku?: string, description?: string}
     * @param  list<array<string, mixed>>  $itemDecisions  {item_id: string, decision: 'approve'|'reject'|'correction', variant_id?: string, platform_rate_minor?: Money, wholesale_enabled?: bool, dropshipping_enabled?: bool, note?: string}
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

        $listing = $this->database->transaction(function () use ($listing, $reviewer, $productDecision, $itemDecisions, $reason, $hasApproval) {
            /** @var SupplierProductListing $locked */
            $locked = SupplierProductListing::query()->lockForUpdate()->findOrFail($listing->id);

            if ($locked->status !== ListingStatus::UnderReview) {
                throw new InvalidArgumentException('This listing is not awaiting a decision.');
            }

            $items = $locked->items()->get()->keyBy('public_id');

            $product = $hasApproval ? $this->resolveProduct($locked, $reviewer, $productDecision) : null;

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
                    'approve' => $this->approveItem($item, $product, $itemDecision, $reviewer),
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
                throw new InvalidArgumentException('Creating a product needs a SKU and a category.');
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
     * @param  array<string, mixed>  $itemDecision
     */
    protected function approveItem(SupplierProductListingItem $item, ?Product $product, array $itemDecision, User $reviewer): void
    {
        if ($product === null) {
            throw new InvalidArgumentException('No product was resolved to connect this item to.');
        }

        $variant = filled($itemDecision['variant_id'] ?? null)
            ? ProductVariant::query()->where('public_id', $itemDecision['variant_id'])->where('product_id', $product->id)->firstOrFail()
            : null;

        if (! isset($itemDecision['platform_rate_minor'])) {
            throw new InvalidArgumentException('A Platform Rate is required to approve a listing item.');
        }

        $currency = Currency::from($item->currency_code);
        $supplierRate = $item->supplier_rate;
        $platformRate = $itemDecision['platform_rate_minor'] instanceof Money
            ? $itemDecision['platform_rate_minor']
            : Money::fromDecimal((string) $itemDecision['platform_rate_minor'], $currency);

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

        $offer->stock()->create(['quantity' => 0]);
        SupplierStockMovement::create([
            'supplier_offer_id' => $offer->id,
            'quantity_before' => 0,
            'quantity_after' => 0,
            'source' => 'initial',
            'actor_type' => 'system',
            'actor_id' => null,
            'reason' => 'Offer created from listing approval.',
            'created_at' => now(),
        ]);

        $item->forceFill([
            'status' => ListingItemStatus::Approved,
            'connected_product_variant_id' => $variant?->id,
            'supplier_offer_id' => $offer->id,
            'decision_note' => $itemDecision['note'] ?? null,
        ])->save();
    }
}
