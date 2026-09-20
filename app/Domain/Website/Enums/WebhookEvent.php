<?php

namespace App\Domain\Website\Enums;

/**
 * What the ERP tells a storefront about (contract §7.1).
 *
 * The contract's events. Order, shipment, return and refund events are listed
 * here because the contract names them, and are emitted once the flows that
 * move those things exist (P5-23, Phase 6); a storefront is documented to
 * tolerate an event it does not yet receive.
 *
 * A webhook is a hint that something changed, never the source of truth
 * (contract §7.5). A storefront re-reads the API for anything that matters.
 */
enum WebhookEvent: string
{
    case ProductPublished = 'product.published';
    case ProductUpdated = 'product.updated';
    case ProductUnpublished = 'product.unpublished';
    case ProductPriceChanged = 'product.price_changed';
    case InventoryUpdated = 'inventory.updated';
    case InventoryOutOfStock = 'inventory.out_of_stock';
    case CategoryUpdated = 'category.updated';
    case OrderStatusChanged = 'order.status_changed';
    case OrderCancelled = 'order.cancelled';

    /*
     * Cash on delivery, told as its own three moments beside the status move
     * they ride with (§6.2, P6-10). A storefront showing a customer "confirm
     * your order" needs the deadline, not a status name it has to interpret —
     * and §8 has consumers tolerate an event type they do not know, so these
     * are an addition to v1 rather than a change to it.
     */
    case CodConfirmationRequired = 'cod.confirmation_required';
    case CodConfirmed = 'cod.confirmed';
    case CodExpired = 'cod.expired';
    case ShipmentUpdated = 'shipment.updated';
    case ReturnStatusChanged = 'return.status_changed';
    case RefundCompleted = 'refund.completed';
    case WebsiteSuspended = 'website.suspended';
    case WebsiteRestored = 'website.restored';

    /**
     * Whether a delivery of this event is about one selected product, and so
     * decides that product's synchronisation status (P5-7).
     */
    public function concernsProduct(): bool
    {
        return in_array($this, [
            self::ProductPublished,
            self::ProductUpdated,
            self::ProductUnpublished,
            self::ProductPriceChanged,
        ], true);
    }
}
