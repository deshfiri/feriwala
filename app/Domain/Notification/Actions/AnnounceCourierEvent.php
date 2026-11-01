<?php

namespace App\Domain\Notification\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Models\Order;
use App\Models\User;
use App\Notifications\Courier\CourierPartnerUpdate;
use App\Notifications\Courier\CourierStaffAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Tells the right people what a courier booking or parcel has just become.
 *
 * **Two audiences, never mixed.** *Staff* who may act on couriers (and Super
 * Admins, platform access only) are told when something needs a person: a
 * booking failed, a booking's outcome is unknown, Steadfast reported a status
 * Banij cannot act on. *The owning Partner* -- the account's owner and nobody
 * else -- is told only about confirmed moves of their parcel. The notification
 * carries the outcome, the order's reference and public id; never a name,
 * number, address, credential, error body or raw payload.
 *
 * **Safe to repeat.** Each id is derived from the event, the subject and the
 * recipient, so a retried job or a repeated webhook writes one bell entry. It
 * runs after the surrounding transaction commits, and a failure to notify is
 * reported and swallowed: the change it announces is never undone by a missed
 * nudge.
 */
class AnnounceCourierEvent
{
    public function __construct(protected NotifyStaffOfSubmission $staff) {}

    /**
     * Staff: a booking attempt failed or has an unknown outcome.
     *
     * @param  string  $alert  `booking_failed` or `booking_uncertain`
     */
    public function bookingNeedsAttention(string $alert, Order $order, string $attemptId): void
    {
        $this->tellStaff($alert, $order->reference, $order->public_id, $attemptId);
    }

    /**
     * Staff: Steadfast's status needs a person to look.
     */
    public function statusNeedsReview(Shipment $shipment, bool $unknownStatus): void
    {
        $order = $shipment->order;

        $this->tellStaff($unknownStatus ? 'status_unknown' : 'status_review', $order->reference, $order->public_id, $shipment->public_id);
    }

    /**
     * Partner: a Steadfast shipment's own status has moved. Only the moves a
     * Partner cares about are announced; manual shipments are not touched.
     */
    public function shipmentMoved(Shipment $shipment, OrderCourierStatus $to): void
    {
        $outcome = match ($to) {
            OrderCourierStatus::Assigned => 'booked',
            OrderCourierStatus::PickedUp => 'picked_up',
            OrderCourierStatus::InTransit => 'in_transit',
            OrderCourierStatus::Delivered => 'delivered',
            OrderCourierStatus::FailedDelivery => 'failed',
            OrderCourierStatus::ReturnedToOrigin => 'returned',
            default => null,
        };

        if ($outcome === null || $shipment->courierProvider->code !== CourierProviderCode::Steadfast) {
            return;
        }

        $order = $shipment->order;
        $accountId = $order->business_account_id;
        $reference = $order->reference;
        $orderId = $order->public_id;
        $shipmentId = $shipment->public_id;

        $this->afterCommit(function () use ($outcome, $accountId, $reference, $orderId, $shipmentId): void {
            $owner = BusinessAccount::query()->find($accountId)?->owner;

            if (! $owner instanceof User) {
                return;
            }

            $notification = new CourierPartnerUpdate($outcome, $reference, $orderId);
            $notification->id = $this->idFor("courier.partner.{$outcome}", $shipmentId, $owner);

            Notification::send($owner, $notification);
        });
    }

    protected function tellStaff(string $alert, string $reference, string $orderId, string $subject): void
    {
        $this->afterCommit(function () use ($alert, $reference, $orderId, $subject): void {
            $permission = PermissionCatalogue::name(PermissionModule::Courier, PermissionAction::Edit);

            foreach ($this->staff->holdersOf($permission) as $recipient) {
                $notification = new CourierStaffAlert($alert, $reference, $orderId);
                $notification->id = $this->idFor("courier.staff.{$alert}", $subject, $recipient);

                Notification::send($recipient, $notification);
            }
        });
    }

    protected function idFor(string $event, string $subject, User $recipient): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "feriwala:{$event}:{$subject}:{$recipient->public_id}")->toString();
    }

    /**
     * After the surrounding transaction commits -- or now, outside one. Never
     * inside it, so a rolled-back step announces nothing.
     */
    protected function afterCommit(callable $announce): void
    {
        DB::afterCommit(function () use ($announce): void {
            try {
                $announce();
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
