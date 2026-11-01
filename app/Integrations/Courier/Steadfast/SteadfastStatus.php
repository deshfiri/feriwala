<?php

namespace App\Integrations\Courier\Steadfast;

use App\Domain\Order\Enums\OrderCourierStatus;

/**
 * Steadfast's own status vocabulary, and what each value means to Banij.
 *
 * The eleven values are the ones Steadfast's maintained package documents
 * (README, "Delivery Statuses"). Matching is **exact** -- no lower-casing, no
 * trimming, no "contains" -- so a value Banij has not seen is `null` from
 * {@see self::fromRaw()} and is treated as unknown, never guessed at.
 *
 * Steadfast has no picked-up or in-transit value: those parcels simply stay
 * `pending`/`in_review` until delivered. So only `delivered` and `cancelled`
 * move Banij's courier status, and `ApplySteadfastStatus` walks the legal
 * intermediate steps to get there.
 *
 *  raw value                            courier target  provider-final  review  financial effect
 *  pending                              -               no              no      no
 *  in_review                            -               no              no      no
 *  hold                                 -               no              YES     no
 *  delivered_approval_pending           -               no              no      no
 *  partial_delivered_approval_pending   -               no              YES     no
 *  cancelled_approval_pending           -               no              no      no
 *  unknown_approval_pending             -               no              YES     no
 *  delivered                            Delivered       yes             no      only via the existing delivery actions
 *  partial_delivered                    -               yes             YES     no  (no design for partial quantities)
 *  cancelled                            Cancelled       yes             no      no
 *  unknown                              -               no              YES     no
 *
 * `*_approval_pending` values are Steadfast's own back-office step and can still
 * change, so they do not move the order; the final value does. A financial
 * effect is only ever the existing delivery pipeline running through the shared
 * status action. Nothing here records COD collection or settlement.
 */
enum SteadfastStatus: string
{
    case Pending = 'pending';
    case InReview = 'in_review';
    case Hold = 'hold';
    case DeliveredApprovalPending = 'delivered_approval_pending';
    case PartialDeliveredApprovalPending = 'partial_delivered_approval_pending';
    case CancelledApprovalPending = 'cancelled_approval_pending';
    case UnknownApprovalPending = 'unknown_approval_pending';
    case Delivered = 'delivered';
    case PartialDelivered = 'partial_delivered';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    /**
     * Exact match only; null for anything not in the documented list.
     */
    public static function fromRaw(?string $raw): ?self
    {
        return $raw === null ? null : self::tryFrom($raw);
    }

    /**
     * The Banij courier status this moves a shipment to, or null when it does
     * not move it.
     */
    public function courierTarget(): ?OrderCourierStatus
    {
        return match ($this) {
            self::Delivered => OrderCourierStatus::Delivered,
            self::Cancelled => OrderCourierStatus::Cancelled,
            default => null,
        };
    }

    /**
     * Whether Steadfast will not change this parcel's status again by itself,
     * so polling for it can stop.
     */
    public function isProviderFinal(): bool
    {
        return in_array($this, [self::Delivered, self::PartialDelivered, self::Cancelled], true);
    }

    /**
     * Whether a person should look: Banij cannot act on it by itself.
     */
    public function requiresReview(): bool
    {
        return in_array($this, [
            self::Hold,
            self::PartialDeliveredApprovalPending,
            self::PartialDelivered,
            self::UnknownApprovalPending,
            self::Unknown,
        ], true);
    }

    /**
     * Whether acting on it may run the existing delivery pipeline (stock sold,
     * Partner profit). Never COD collection, which is a separate staff-recorded
     * fact.
     */
    public function allowsFinancialEffect(): bool
    {
        return $this === self::Delivered;
    }

    /**
     * Raw values that end a parcel's life at Steadfast, for the poller's query.
     *
     * @return array<int, string>
     */
    public static function finalValues(): array
    {
        return array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->isProviderFinal()),
        );
    }
}
