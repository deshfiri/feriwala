<?php

namespace App\Domain\Order\Queries;

use App\Domain\Account\VerificationCodes;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Order\Actions\SendCodConfirmationCode;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Carbon\CarbonImmutable;

/**
 * Where a cash-on-delivery order's confirmation stands, for the people who may
 * look at it (§6.2, §18.4, §18.5, P6-10).
 *
 * **Never the code.** It is held hashed and is readable by nobody — not the
 * partner whose sale it is, not the staff member looking at the order, not this
 * class. What is shown is the shape of the thing around it: whether a code is
 * outstanding, until when, and when another may be sent.
 *
 * Staff see one field more — how many wrong guesses have been used — because
 * "the customer says the code does not work" is answered by it and it gives away
 * nothing about the code itself. A partner does not: a countdown of guesses on
 * a screen a shop owner can open is a guessing aid for anybody who gets to that
 * screen.
 */
class CodConfirmationState
{
    public function __construct(
        protected SendCodConfirmationCode $codes,
        protected VerificationCodes $verification,
    ) {}

    /**
     * @return array{
     *     state: string,
     *     expires_at: string|null,
     *     code_outstanding: bool,
     *     resend_available_in: int,
     *     attempts_used?: int,
     *     attempts_allowed?: int,
     *     code_delivery?: string|null,
     *     sends_used?: int,
     *     sends_allowed?: int,
     * }|null  null when the order is not paid on delivery
     */
    public function for(Order $order, bool $forStaff = false): ?array
    {
        if (! $order->isCashOnDelivery()) {
            return null;
        }

        $waiting = $order->status === OrderStatus::CustomerVerificationPending;

        $state = [
            'state' => match (true) {
                $waiting => 'pending',
                $order->status === OrderStatus::Cancelled => 'cancelled',
                default => 'confirmed',
            },
            // A deadline only while there is still something to meet it.
            'expires_at' => $waiting ? $this->deadline($order)?->toIso8601String() : null,
            'code_outstanding' => $waiting && $this->codes->isPending($order),
            'resend_available_in' => $waiting ? $this->codes->secondsUntilResend($order) : 0,
        ];

        if (! $forStaff) {
            return $state;
        }

        return [
            ...$state,
            'attempts_used' => $this->verification->attemptsUsed(
                SendCodConfirmationCode::PURPOSE,
                $this->codes->identifierFor($order),
            ),
            'attempts_allowed' => VerificationCodes::MAX_ATTEMPTS,
            // Whether the last code reached the SMS provider, and how many of
            // the order's codes are spent — the delivery, never the message.
            'code_delivery' => $this->codes->deliveryState($order),
            'sends_used' => $this->codes->sendsUsed($order),
            'sends_allowed' => SendCodConfirmationCode::MAX_SENDS,
        ];
    }

    /**
     * When the time to confirm runs out.
     *
     * The stock's deadline, because that is the one that decides: a confirmation
     * after it would commit units the sweep has already given back. A released
     * reservation's deadline is history, not a countdown, so it is left out.
     */
    protected function deadline(Order $order): ?CarbonImmutable
    {
        $reservations = $order->items->map(fn (OrderItem $item) => $item->stockReservation)
            ->filter(fn (?StockReservation $reservation) => $reservation?->status === StockReservationStatus::Active)
            ->map(fn (StockReservation $reservation) => $reservation->expires_at)
            ->filter();

        if ($reservations->isNotEmpty()) {
            /** @var CarbonImmutable $earliest */
            $earliest = $reservations->min();

            return $earliest;
        }

        return $order->payment?->expires_at;
    }
}
