<?php

namespace App\Domain\Damage\Exceptions;

use RuntimeException;

/**
 * A damage case the ERP will not open, or a step on one it will not take now
 * (D35).
 *
 * Carries the HTTP status and a stable code, in the same shape as a return's
 * refusal, so one answer serves the Partner's screen and the staff screen.
 * Details never name another Partner's data, a warehouse, a cost or an internal
 * identifier.
 */
class DamageRefused extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function notDelivered(): self
    {
        return new self(409, 'order_not_delivered', 'Damage can only be reported on an order that has been delivered.');
    }

    public static function windowClosed(?string $closedAt = null): self
    {
        return new self(409, 'damage_window_closed', 'The time to report damage on this order has passed.', array_filter([
            'window_closed_at' => $closedAt,
        ]));
    }

    public static function lineNotOnOrder(): self
    {
        return new self(422, 'line_not_on_order', 'That item is not on this order.');
    }

    public static function quantityNotAvailable(int $requested, int $available): self
    {
        return new self(422, 'damage_quantity_not_available', sprintf('Only %d of this item can still be reported damaged.', $available), [
            'requested' => $requested,
            'available' => $available,
        ]);
    }

    public static function needsEvidence(): self
    {
        return new self(422, 'damage_needs_evidence', 'Describe the damage, or attach evidence of it.');
    }

    public static function tooMuchEvidence(): self
    {
        return new self(422, 'damage_too_much_evidence', 'Attach at most ten pieces of evidence.');
    }

    public static function returnNotInspected(): self
    {
        return new self(409, 'return_not_inspected', 'A damage can only be opened from returned goods once the parcel has been received and inspected.');
    }

    public static function returnLineNotDamageable(): self
    {
        return new self(422, 'return_line_not_damageable', 'These units were already put back on sale when the return was inspected.');
    }

    public static function supplierBacked(): self
    {
        return new self(422, 'damage_supplier_backed', 'Damage on an item supplied by a Supplier is settled with the Supplier, not here.');
    }

    public static function forbidden(): self
    {
        return new self(403, 'damage_forbidden', 'This account may not do that.');
    }

    public static function notInThatState(): self
    {
        return new self(409, 'damage_not_in_that_state', 'This damage is not at a stage where that can be done.');
    }

    public static function parcelNotReceived(): self
    {
        return new self(409, 'damage_parcel_not_received', 'The item has not been received yet, so it cannot be inspected.');
    }

    public static function notInspected(): self
    {
        return new self(409, 'damage_not_inspected', 'The item has not been inspected, so nothing can be decided or posted.');
    }

    public static function wrongWorkflow(): self
    {
        return new self(409, 'damage_wrong_workflow', 'That is not something this damage allows.');
    }

    public static function needsAWarehouse(): self
    {
        return new self(422, 'damage_needs_a_warehouse', 'Say which warehouse received the item.');
    }

    public static function inspectionMustAccountForEveryUnit(int $quantity, int $given): self
    {
        return new self(422, 'damage_inspection_incomplete', sprintf('Every one of the %d units needs a disposition; %d were given.', $quantity, $given), [
            'quantity' => $quantity,
            'given' => $given,
        ]);
    }

    public static function dispositionNeedsCustody(): self
    {
        return new self(422, 'damage_disposition_needs_custody', 'An item that is not in Banij\'s hands can only be recorded as damaged: there is no stock to move.');
    }

    public static function inspectionNeedsACause(): self
    {
        return new self(422, 'damage_inspection_needs_a_cause', 'Record what caused the damage.');
    }

    public static function nothingToRestore(string $sku): self
    {
        return new self(409, 'damage_nothing_to_move', sprintf('There is no stock of BPC %s to move those units from.', $sku), ['sku' => $sku]);
    }

    public static function decisionNeedsAReason(): self
    {
        return new self(422, 'damage_decision_needs_a_reason', 'Give the reason for this decision.');
    }

    public static function responsibilityNeedsAReason(): self
    {
        return new self(422, 'damage_responsibility_needs_a_reason', 'Give the reason responsibility is being changed.');
    }

    public static function lossIsNegative(): self
    {
        return new self(422, 'damage_loss_is_negative', 'A loss cannot be negative.');
    }

    public static function lossExceedsMaximum(string $maximum): self
    {
        return new self(422, 'damage_loss_exceeds_maximum', sprintf('The loss cannot be more than the item was worth when it was sold (%s).', $maximum), [
            'maximum' => $maximum,
        ]);
    }

    public static function lossNeedsAWriteOffReason(): self
    {
        return new self(422, 'damage_zero_loss_needs_a_reason', 'A loss of nothing is a write-off: say why.');
    }

    public static function currencyMismatch(): self
    {
        return new self(422, 'damage_currency_mismatch', 'The loss must be in the currency of the order.');
    }

    public static function responsibilityFixedOnceDispatching(): self
    {
        return new self(409, 'damage_responsibility_fixed', 'Responsibility cannot change once the item is being sent to the Partner.');
    }

    public static function notPartnerLiable(): self
    {
        return new self(409, 'damage_not_partner_liable', 'Only an item the Partner bears the loss for can be sent to them.');
    }

    public static function noDamagedUnits(): self
    {
        return new self(409, 'damage_no_damaged_units', 'There are no damaged units held to send.');
    }

    public static function alreadyRequested(): self
    {
        return new self(409, 'damage_delivery_already_requested', 'Delivery of this item has already been requested.');
    }

    public static function addressNotAllowed(): self
    {
        return new self(422, 'damage_address_not_allowed', 'Choose one of your own saved addresses.');
    }

    public static function insufficientBalance(string $needed): self
    {
        return new self(402, 'damage_delivery_balance_too_low', 'Your wallet balance does not cover the delivery charge.', [
            'needed' => $needed,
        ]);
    }

    public static function notRequested(): self
    {
        return new self(409, 'damage_delivery_not_requested', 'Delivery of this item has not been requested.');
    }

    public static function trackingRequired(): self
    {
        return new self(422, 'damage_tracking_required', 'Give the tracking reference the item was sent under.');
    }

    public static function notDispatched(): self
    {
        return new self(409, 'damage_not_dispatched', 'This item has not been dispatched yet.');
    }

    public static function busy(): self
    {
        return new self(409, 'damage_busy', 'Somebody else is working on this damage. Try again in a moment.');
    }
}
