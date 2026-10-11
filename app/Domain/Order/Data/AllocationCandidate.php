<?php

namespace App\Domain\Order\Data;

use App\Domain\Order\Actions\ConfirmProductSourceLink;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Models\ProductSourceLink;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Support\Money\Money;

/**
 * One source a member of staff could allocate an order line to, with
 * everything they need to choose between it and the others.
 *
 * **A staff-only shape.** `unit_cost`, `expected_margin` and the Supplier's
 * identity are exactly the figures D25 keeps away from Clients, Partners, the
 * Storefront API and partner-facing order views — nothing here may be handed to
 * a `WebsiteOrderPayload`, a catalogue prop, or any response a partner reads.
 * It exists to be rendered on the staff allocation panel and nowhere else.
 *
 * The platform does not rank these. `isEligible` says whether a source
 * *could* serve the line; which one *should* is the staff decision the batch
 * requires, and sorting the list by margin is a convenience, never a choice
 * made on someone's behalf.
 */
class AllocationCandidate
{
    /**
     * @param  string  $sourceId  the warehouse's or offer's public id for a source directly catalogued under the ordered product/variation; the **stock item's own** public id (never the warehouse's) for a warehouse source reached through a confirmed cross-catalogue link, since one warehouse can hold more than one linked product — never a database id either way
     * @param  int  $availableToPromise  what this source could actually commit to the line right now
     * @param  string|null  $ineligibleReason  why this source cannot serve the line, when it cannot
     */
    public function __construct(
        public readonly AllocationSourceType $sourceType,
        public readonly string $sourceId,
        public readonly string $sourceLabel,
        public readonly int $available,
        public readonly int $reserved,
        public readonly int $availableToPromise,
        public readonly Money $unitCost,
        public readonly Money $platformRate,
        public readonly Money $expectedMargin,
        public readonly string $currencyCode,
        public readonly bool $isEligible,
        public readonly ?string $ineligibleReason = null,
        public readonly bool $isCurrentlyAllocated = false,

        // Supplier-only detail. Null throughout for a warehouse candidate.
        public readonly ?string $supplierId = null,
        public readonly ?string $supplierName = null,
        public readonly ?int $leadTimeDays = null,
        public readonly bool $isPreferred = false,
        public readonly ?string $supplierStatus = null,
        public readonly ?string $offerStatus = null,

        /**
         * How this Supplier offer can actually be fulfilled (Supplier Bulk
         * Product Listing batch, correction 8) -- `ReadyStock` for a
         * warehouse candidate, since the concept does not apply to one.
         */
        public readonly SupplyMode $supplyMode = SupplyMode::ReadyStock,

        /** The declared ceiling on concurrent on_demand/pre_order commitments, when the offer set one. */
        public readonly ?int $fulfilmentCapacity = null,

        /**
         * True whenever this source is not ready_stock: allocating it
         * commits the Supplier rather than drawing down real stock, so the
         * panel must ask staff to acknowledge that before it is confirmed.
         */
        public readonly bool $requiresConfirmation = false,

        /**
         * Whether this source is already known to fulfil the ordered
         * product/variation — either because it is directly catalogued
         * under it (the historical, only case this DTO used to carry), or
         * because a {@see ProductSourceLink} has
         * confirmed it. False for a catalogue-wide search result nobody has
         * confirmed yet — {@see ConfirmProductSourceLink}
         * is required before it can be allocated.
         */
        public readonly bool $isRelated = true,

        // Set only when this source is catalogued under a *different*
        // product/variation than the order line's own — what a confirmation
        // dialog compares the ordered item against, and what a reservation
        // must actually be taken against instead of the order line's own
        // product.
        public readonly ?int $sourceProductId = null,
        public readonly ?int $sourceProductVariantId = null,
        public readonly ?string $sourceProductName = null,
        public readonly ?string $sourceProductSku = null,

        /**
         * How this source came to be offered: `exact` (catalogued under the
         * ordered product/variation), `linked_product` (a Product staff
         * linked as the same Product, on a variation they matched) or
         * `linked` (a source staff confirmed one by one).
         */
        public readonly string $matchKind = 'exact',

        /** The 9-character BPC of the Product this source is catalogued under. */
        public readonly ?string $sourceProductBpc = null,

        /** The variation of that Product, when it has one. */
        public readonly ?string $sourceVariantLabel = null,

        /**
         * What the Supplier would be owed for the quantity this candidate was
         * judged against, at the offer's Supplier Rate. Display only — the
         * payable itself is raised by the allocation, from the snapshotted
         * rate. Null for a warehouse, which owes nobody.
         */
        public readonly ?Money $expectedPayable = null,
    ) {}

    /**
     * The shape the staff panel receives.
     *
     * Money is serialised by `Money::jsonSerialize()` so the client renders the
     * server's own figure and never computes one (D26).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType->value,
            'source_id' => $this->sourceId,
            'source_label' => $this->sourceLabel,
            'available' => $this->available,
            'reserved' => $this->reserved,
            'available_to_promise' => $this->availableToPromise,
            'unit_cost' => $this->unitCost,
            'platform_rate' => $this->platformRate,
            'expected_margin' => $this->expectedMargin,
            'currency_code' => $this->currencyCode,
            'is_eligible' => $this->isEligible,
            'ineligible_reason' => $this->ineligibleReason,
            'is_currently_allocated' => $this->isCurrentlyAllocated,
            'supplier_id' => $this->supplierId,
            'supplier_name' => $this->supplierName,
            'lead_time_days' => $this->leadTimeDays,
            'is_preferred' => $this->isPreferred,
            'supplier_status' => $this->supplierStatus,
            'offer_status' => $this->offerStatus,
            'supply_mode' => $this->supplyMode->value,
            'supply_mode_label' => $this->supplyMode->label(),
            'buyer_facing_availability_label' => $this->supplyMode->buyerFacingLabel(),
            'fulfilment_capacity' => $this->fulfilmentCapacity,
            'requires_confirmation' => $this->requiresConfirmation,
            'is_related' => $this->isRelated,
            'source_product_name' => $this->sourceProductName,
            'source_product_sku' => $this->sourceProductSku,
            'source_product_bpc' => $this->sourceProductBpc,
            'source_variant_label' => $this->sourceVariantLabel,
            'expected_payable' => $this->expectedPayable,
            'match_kind' => $this->matchKind,
        ];
    }
}
