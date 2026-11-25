<?php

namespace App\Domain\Courier\Enums;

use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderSource;

/**
 * Where a parcel's shipping label stands, as staff and the Partner see it.
 *
 * Derived, never stored: from the shipment's current label version and the
 * reason, if any, one could not be issued.
 */
enum ShipmentLabelStatus: string
{
    /** Not a Storefront parcel, cancelled, or not attempted yet. */
    case NotAvailable = 'not_available';

    /** Something must arrive or be fixed first: the courier's references, the recipient, the warehouse. */
    case NeedsInformation = 'needs_information';

    /** The first version is issued and printable. */
    case Ready = 'ready';

    /** A corrected version replaced the first; it is the one printed. */
    case Corrected = 'corrected';

    public static function of(Shipment $shipment, ?ShipmentLabel $current): self
    {
        if ($current !== null) {
            return $current->version > 1 ? self::Corrected : self::Ready;
        }

        if ($shipment->status === OrderCourierStatus::Cancelled || $shipment->order->source !== OrderSource::Website) {
            return self::NotAvailable;
        }

        return $shipment->label_blocker !== null ? self::NeedsInformation : self::NotAvailable;
    }

    public function label(): string
    {
        return __("shipping_label.status.{$this->value}");
    }

    public function tone(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Corrected => 'info',
            self::NeedsInformation => 'warning',
            self::NotAvailable => 'neutral',
        };
    }

    public function isPrintable(): bool
    {
        return $this === self::Ready || $this === self::Corrected;
    }
}
