<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Courier\Enums\CourierBookingStatus;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Exceptions\CourierBookingRefused;
use App\Domain\Courier\Exceptions\CourierProviderUnavailable;
use App\Domain\Courier\Models\CourierBookingAttempt;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Queries\SteadfastOrderRequestBuilder;
use App\Domain\Notification\Actions\AnnounceCourierEvent;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\StoreOrderStep;
use App\Domain\Order\Models\Order;
use App\Integrations\Courier\CourierManager;
use App\Integrations\Courier\Steadfast\SteadfastClient;
use App\Integrations\Courier\Steadfast\SteadfastException;
use App\Integrations\Courier\Steadfast\SteadfastOrderRequest;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * Books one Store Order's parcel with Steadfast. **Every real call creates a
 * live, potentially chargeable consignment.**
 *
 * The sequence, and why it is in this order:
 *
 *  1. Under a row lock on the order, refuse anything unsafe and record a
 *     `pending` attempt, then commit. The partial unique index allows one live
 *     attempt per order, so two staff clicking at once produce one call.
 *  2. Call Steadfast **outside** any database transaction -- a network call
 *     must never hold a lock, and a rollback must never pretend a parcel was
 *     not booked.
 *  3. A definite answer is recorded: booked (shipment created through the
 *     shared action) or failed (Steadfast refused, or the request provably never
 *     left). Anything else -- a timeout, a dropped connection, an answer Banij
 *     cannot read -- is `uncertain`, and is **never** retried blind: it has to
 *     be reconciled by invoice first, or a person must confirm it was not booked.
 *
 * Banij's own reference is Steadfast's `invoice`, the one identifier both sides
 * know before any reply.
 */
class BookSteadfastShipment
{
    public function __construct(
        protected DatabaseManager $database,
        protected CourierManager $courier,
        protected SteadfastOrderRequestBuilder $requests,
        protected SteadfastClient $client,
        protected RecordSteadfastBooking $record,
        protected RecordAuditLog $audit,
        protected AnnounceCourierEvent $announce,
    ) {}

    /**
     * @throws CourierBookingRefused before any attempt exists or any call is made
     * @throws CourierProviderUnavailable when Steadfast is not switched on
     */
    public function handle(Order $order, User $actor): CourierBookingAttempt
    {
        if (! $this->courier->isAvailable(CourierProviderCode::Steadfast)) {
            throw CourierProviderUnavailable::forProvider(
                CourierProviderCode::Steadfast,
                $this->courier->isImplemented(CourierProviderCode::Steadfast),
            );
        }

        [$attempt, $request] = $this->begin($order, $actor);

        try {
            $consignment = $this->client->createOrder($request);
        } catch (SteadfastException $exception) {
            return $this->recordFailure($attempt, $actor, $exception);
        }

        // Remembered at once: if the local records cannot be written next, the
        // parcel still exists at Steadfast and reconciliation needs its id.
        $attempt->forceFill(['request_snapshot' => $attempt->request_snapshot + [
            'consignment_id' => $consignment->consignmentId,
            'tracking_code' => $consignment->trackingCode,
            'raw_status' => $consignment->status,
        ]])->save();

        try {
            $this->record->handle($attempt, $actor, $consignment->consignmentId, $consignment->trackingCode, $consignment->status);
        } catch (Throwable $exception) {
            report($exception);

            return $this->markUncertain($attempt, $actor, 'local_failure');
        }

        return $attempt->refresh();
    }

    /**
     * @return array{0: CourierBookingAttempt, 1: SteadfastOrderRequest}
     */
    protected function begin(Order $order, User $actor): array
    {
        return $this->database->transaction(function () use ($order, $actor): array {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            /** @var CourierBookingAttempt|null $live */
            $live = CourierBookingAttempt::query()
                ->where('order_id', $locked->id)
                ->whereIn('status', [CourierBookingStatus::Pending->value, CourierBookingStatus::Booked->value, CourierBookingStatus::Uncertain->value])
                ->first();

            if ($live !== null) {
                throw CourierBookingRefused::because(match ($live->status) {
                    CourierBookingStatus::Uncertain => 'needs_reconciliation',
                    CourierBookingStatus::Booked => 'already_booked',
                    default => 'already_in_progress',
                });
            }

            // A Store Order's courier and delivery start only once it is Dispatched
            // (the shared shipment action enforces this too, but only *after* the
            // call -- a parcel booked and then unrecordable is what this prevents).
            // Booking never dispatches: Dispatch is the step that consumes stock.
            if ($locked->source === OrderSource::Website && ! StoreOrderStep::hasDispatched($locked->status)) {
                throw CourierBookingRefused::because('not_dispatched');
            }

            $hasShipment = Shipment::query()
                ->where('order_id', $locked->id)
                ->where('status', '!=', OrderCourierStatus::Cancelled->value)
                ->exists();

            // Checked before the call: a parcel booked with Steadfast that Banij
            // then cannot record is the failure this whole sequence exists to avoid.
            if ($hasShipment
                || ! $locked->canTransitionTo(OrderCourierStatus::Assigned, 'courier_status')
                || ! $locked->canTransitionTo(OrderDeliveryStatus::CourierAssigned, 'delivery_status')) {
                throw CourierBookingRefused::because('order_not_ready');
            }

            $request = $this->requests->handle($locked);

            /** @var CourierProvider $provider */
            $provider = CourierProvider::query()->where('code', CourierProviderCode::Steadfast->value)->firstOrFail();

            $attempt = CourierBookingAttempt::query()->create([
                'order_id' => $locked->id,
                'courier_provider_id' => $provider->id,
                'attempted_by' => $actor->id,
                'status' => CourierBookingStatus::Pending,
                'idempotency_key' => 'steadfast:'.$locked->public_id.':'.Str::ulid(),
                'request_snapshot' => $request->summary(),
                'started_at' => now(),
            ]);

            $this->audit(
                $attempt,
                $actor,
                'courier.booking_requested',
                ['order' => $locked->reference, 'provider' => 'steadfast'],
            );

            return [$attempt, $request];
        });
    }

    protected function recordFailure(CourierBookingAttempt $attempt, User $actor, SteadfastException $exception): CourierBookingAttempt
    {
        if ($exception->kind->outcomeIsUnknown()) {
            return $this->markUncertain($attempt, $actor, $exception->kind->value);
        }

        return $this->settle($attempt, $actor, CourierBookingStatus::Failed, $exception->kind->value, $exception->kind->message(), 'courier.booking_failed');
    }

    protected function markUncertain(CourierBookingAttempt $attempt, ?User $actor, string $code): CourierBookingAttempt
    {
        return $this->settle($attempt, $actor, CourierBookingStatus::Uncertain, $code, __('courier.booking.uncertain_message'), 'courier.booking_uncertain');
    }

    protected function settle(CourierBookingAttempt $attempt, ?User $actor, CourierBookingStatus $status, string $code, string $message, string $action): CourierBookingAttempt
    {
        return $this->database->transaction(function () use ($attempt, $actor, $status, $code, $message, $action): CourierBookingAttempt {
            /** @var CourierBookingAttempt $locked */
            $locked = CourierBookingAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            // Only a still-pending attempt is settled here: one that something
            // else already resolved is not overwritten.
            if ($locked->status !== CourierBookingStatus::Pending) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $status,
                'failure_code' => $code,
                'failure_message' => mb_substr($message, 0, 255),
                'completed_at' => $status === CourierBookingStatus::Failed ? now() : null,
            ])->save();

            $this->audit($locked, $actor, $action, ['status' => $status->value, 'failure_code' => $code]);

            // Failed and uncertain bookings both need a person; told after commit.
            $this->announce->bookingNeedsAttention(
                $status === CourierBookingStatus::Failed ? 'booking_failed' : 'booking_uncertain',
                $locked->order,
                $locked->public_id,
            );

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $after
     */
    protected function audit(CourierBookingAttempt $attempt, ?User $actor, string $action, array $after): void
    {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor?->id,
            auditableType: CourierBookingAttempt::class,
            auditableId: $attempt->id,
            after: $after,
            module: 'courier',
        ));
    }
}
