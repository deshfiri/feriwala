<?php

namespace App\Domain\Catalog\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * Every product status §11.2 names, with the moves each allows.
 *
 * §11.2 lists eleven statuses, but they answer three different questions, and
 * one column cannot hold all three answers at once: a product is Active **and**
 * Dropshipping Enabled **and** Wholesale Disabled simultaneously. So the eleven
 * are one vocabulary on three axes:
 *
 *   - **lifecycle** — Draft, Pending Review, Active, Inactive, Out of Stock,
 *     Discontinued, Archived — held in `products.status`;
 *   - **dropshipping** — Enabled or Disabled;
 *   - **wholesale** — Enabled or Disabled.
 *
 * A state only ever moves to another state on its own axis, and each axis lives
 * in its own column (the channel columns arrive with P3-10). Declaring all eleven
 * here keeps §11.2's list in one place, where a twelfth added by somebody later
 * has to say which axis it belongs to.
 */
enum ProductStatus: string implements TransitionableState
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Active = 'active';
    case Inactive = 'inactive';
    case OutOfStock = 'out_of_stock';
    case Discontinued = 'discontinued';
    case Archived = 'archived';

    case DropshippingEnabled = 'dropshipping_enabled';
    case DropshippingDisabled = 'dropshipping_disabled';

    case WholesaleEnabled = 'wholesale_enabled';
    case WholesaleDisabled = 'wholesale_disabled';

    public const AXIS_LIFECYCLE = 'lifecycle';

    public const AXIS_DROPSHIPPING = 'dropshipping';

    public const AXIS_WHOLESALE = 'wholesale';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            // Nothing reaches a partner without somebody reviewing it first.
            self::Draft => [self::PendingReview, self::Archived],

            // Review ends in approval or a return to the author.
            self::PendingReview => [self::Draft, self::Active, self::Archived],

            self::Active => [self::Inactive, self::OutOfStock, self::Discontinued, self::Archived],
            self::Inactive => [self::Active, self::Discontinued, self::Archived],

            // Set by hand today; the inventory tasks (P3.C) will drive it.
            self::OutOfStock => [self::Active, self::Inactive, self::Discontinued, self::Archived],

            // No longer made or sourced. It can be retired, not revived: a
            // product that comes back is a decision to review again.
            self::Discontinued => [self::Archived],

            /*
             * The retired record. It never returns straight to sale — only to
             * Draft, which means it passes review again before any partner can
             * offer it.
             */
            self::Archived => [self::Draft],

            self::DropshippingEnabled => [self::DropshippingDisabled],
            self::DropshippingDisabled => [self::DropshippingEnabled],

            self::WholesaleEnabled => [self::WholesaleDisabled],
            self::WholesaleDisabled => [self::WholesaleEnabled],
        };
    }

    public function isTerminal(): bool
    {
        return $this->transitionsTo() === [];
    }

    public function axis(): string
    {
        return match ($this) {
            self::DropshippingEnabled, self::DropshippingDisabled => self::AXIS_DROPSHIPPING,
            self::WholesaleEnabled, self::WholesaleDisabled => self::AXIS_WHOLESALE,
            default => self::AXIS_LIFECYCLE,
        };
    }

    /**
     * The statuses `products.status` may hold.
     *
     * @return array<int, self>
     */
    public static function lifecycle(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status) => $status->axis() === self::AXIS_LIFECYCLE,
        ));
    }

    /**
     * Whether moving here needs a written reason.
     *
     * Retiring a product is the move somebody will later ask about — "why can
     * we not sell this any more" — and the answer should be on the record.
     */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Discontinued, self::Archived], true);
    }

    /**
     * Whether a product in this status may be offered to anybody at all.
     */
    public function isSellable(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Pending review',
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::OutOfStock => 'Out of stock',
            self::Discontinued => 'Discontinued',
            self::Archived => 'Archived',
            self::DropshippingEnabled => 'Dropshipping enabled',
            self::DropshippingDisabled => 'Dropshipping disabled',
            self::WholesaleEnabled => 'Wholesale enabled',
            self::WholesaleDisabled => 'Wholesale disabled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active, self::DropshippingEnabled, self::WholesaleEnabled => 'success',
            self::PendingReview => 'info',
            self::OutOfStock, self::Inactive => 'warning',
            self::Discontinued => 'danger',
            self::Draft, self::Archived, self::DropshippingDisabled, self::WholesaleDisabled => 'neutral',
        };
    }
}
