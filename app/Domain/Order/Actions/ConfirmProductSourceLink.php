<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\ProductSourceLinkStatus;
use App\Domain\Order\Exceptions\AllocationRefused;
use App\Domain\Order\Models\ProductSourceLink;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Staff confirm that a Supplier offer or warehouse stock item, catalogued
 * under a different product/variation, is the same real-world fulfilment
 * item as an ordered one — see `product_source_links`' own migration
 * docblock for why this exists only for that cross-catalogue case.
 *
 * Reuses an existing active link rather than creating a duplicate (the
 * partial unique indexes are the last-resort backstop for the race; this is
 * the fast, non-racy path). Refuses a source that is not itself approved and
 * active — confirming a relationship to a suspended offer or an inactive
 * warehouse's stock would let a later allocation reach a source that was
 * never eligible to begin with. Refuses a source that is already this exact
 * product/variation — that needs no confirmation, and the database's own
 * `product_source_links_is_cross_catalogue` trigger backstops this too.
 */
class ConfirmProductSourceLink
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(
        Product $orderedProduct,
        ?ProductVariant $orderedVariant,
        AllocationSourceType $sourceType,
        string $sourcePublicId,
        User $actor,
        string $reason,
    ): ProductSourceLink {
        $orderedVariantId = $orderedVariant?->id;

        $existing = ProductSourceLink::query()
            ->forOrderedProduct($orderedProduct->id, $orderedVariantId)
            ->where('source_type', $sourceType)
            ->when(
                $sourceType === AllocationSourceType::Warehouse,
                fn ($query) => $query->whereHas('stockItem', fn ($q) => $q->where('public_id', $sourcePublicId)),
                fn ($query) => $query->whereHas('supplierOffer', fn ($q) => $q->where('public_id', $sourcePublicId)),
            )
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return match ($sourceType) {
            AllocationSourceType::Warehouse => $this->confirmWarehouse($orderedProduct, $orderedVariant, $sourcePublicId, $actor, $reason),
            AllocationSourceType::SupplierOffer => $this->confirmSupplierOffer($orderedProduct, $orderedVariant, $sourcePublicId, $actor, $reason),
        };
    }

    protected function confirmWarehouse(
        Product $orderedProduct,
        ?ProductVariant $orderedVariant,
        string $stockItemPublicId,
        User $actor,
        string $reason,
    ): ProductSourceLink {
        /** @var StockItem $stockItem */
        $stockItem = StockItem::query()
            ->whereHas('warehouse', fn ($q) => $q->where('is_active', true))
            ->where('public_id', $stockItemPublicId)
            ->firstOr(fn () => throw AllocationRefused::because('That warehouse stock item is not active.'));

        if ($stockItem->product_id === $orderedProduct->id && $stockItem->product_variant_id === $orderedVariant?->id) {
            throw AllocationRefused::because('That stock item already is this exact product — no relationship to confirm.');
        }

        return $this->create($orderedProduct, $orderedVariant, AllocationSourceType::Warehouse, $stockItem->id, null, $actor, $reason);
    }

    protected function confirmSupplierOffer(
        Product $orderedProduct,
        ?ProductVariant $orderedVariant,
        string $offerPublicId,
        User $actor,
        string $reason,
    ): ProductSourceLink {
        /** @var SupplierOffer $offer */
        $offer = SupplierOffer::query()
            ->with('supplier')
            ->where('public_id', $offerPublicId)
            ->where('status', OfferStatus::Active)
            ->firstOr(fn () => throw AllocationRefused::because('That Supplier offer is not active.'));

        if (! $offer->supplier->isOperational()) {
            throw AllocationRefused::because('That Supplier is not operational.');
        }

        if ($offer->product_id === $orderedProduct->id && $offer->product_variant_id === $orderedVariant?->id) {
            throw AllocationRefused::because('That Supplier offer already is this exact product — no relationship to confirm.');
        }

        return $this->create($orderedProduct, $orderedVariant, AllocationSourceType::SupplierOffer, null, $offer->id, $actor, $reason);
    }

    protected function create(
        Product $orderedProduct,
        ?ProductVariant $orderedVariant,
        AllocationSourceType $sourceType,
        ?int $stockItemId,
        ?int $supplierOfferId,
        User $actor,
        string $reason,
    ): ProductSourceLink {
        try {
            return $this->database->transaction(function () use ($orderedProduct, $orderedVariant, $sourceType, $stockItemId, $supplierOfferId, $actor, $reason) {
                $link = ProductSourceLink::create([
                    'ordered_product_id' => $orderedProduct->id,
                    'ordered_product_variant_id' => $orderedVariant?->id,
                    'source_type' => $sourceType,
                    'warehouse_stock_item_id' => $stockItemId,
                    'supplier_offer_id' => $supplierOfferId,
                    'status' => ProductSourceLinkStatus::Active,
                    'confirmed_by' => $actor->id,
                    'confirmed_at' => now(),
                    'confirmation_reason' => $reason,
                ]);

                $this->audit->handle(new AuditEntry(
                    action: 'product_source_link.confirmed',
                    auditableType: ProductSourceLink::class,
                    auditableId: $link->id,
                    after: [
                        'ordered_product_id' => $orderedProduct->id,
                        'source_type' => $sourceType->value,
                    ],
                    accountId: $actor->id,
                    module: PermissionModule::Catalog->value,
                    isSensitive: $sourceType === AllocationSourceType::SupplierOffer,
                ));

                return $link;
            });
        } catch (UniqueConstraintViolationException) {
            $racedWith = ProductSourceLink::query()
                ->forOrderedProduct($orderedProduct->id, $orderedVariant?->id)
                ->where('source_type', $sourceType)
                ->where('warehouse_stock_item_id', $stockItemId)
                ->where('supplier_offer_id', $supplierOfferId)
                ->first();

            if ($racedWith === null) {
                throw AllocationRefused::because('That relationship could not be confirmed.');
            }

            return $racedWith;
        }
    }
}
