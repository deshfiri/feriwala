<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Courier\Enums\CourierBookingStatus;
use App\Domain\Courier\Models\CourierBookingAttempt;
use App\Domain\Order\Models\Order;
use App\Integrations\Courier\Steadfast\SteadfastClient;
use App\Integrations\Courier\Steadfast\SteadfastException;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Settles an uncertain booking without ever booking again.
 *
 * An attempt is uncertain when Banij does not know whether Steadfast holds the
 * parcel. Two ways to find out, and neither creates a consignment:
 *
 *  - Steadfast confirmed it and Banij failed to record it locally -- the
 *    consignment id is already on the attempt -- so the local records are
 *    finished with no call at all.
 *  - Otherwise Steadfast is asked about Banij's own invoice reference
 *    (`GET /status_by_invoice/{invoice}`). Found: the parcel exists, so the
 *    shipment is created and flagged for review (that answer carries no
 *    consignment id or tracking code). Clearly not found (HTTP 404): the attempt
 *    is failed, and only then may booking be tried again. Anything else leaves
 *    it uncertain.
 */
class ReconcileSteadfastBooking
{
    public function __construct(
        protected DatabaseManager $database,
        protected SteadfastClient $client,
        protected RecordSteadfastBooking $record,
        protected RecordAuditLog $audit,
    ) {}

    public function handle(CourierBookingAttempt $attempt, ?User $actor): CourierBookingAttempt
    {
        $attempt->refresh();

        if ($attempt->status !== CourierBookingStatus::Uncertain) {
            return $attempt;
        }

        $snapshot = $attempt->request_snapshot ?? [];

        if (isset($snapshot['consignment_id'])) {
            return $this->recordLocally($attempt, $actor, $snapshot['consignment_id'], $snapshot['tracking_code'] ?? null, $snapshot['raw_status'] ?? null);
        }

        /** @var Order $order */
        $order = Order::query()->findOrFail($attempt->order_id);

        try {
            $lookup = $this->client->statusByInvoice($order->reference);
        } catch (SteadfastException $exception) {
            return $this->stillUncertain($attempt, $exception->kind->message());
        }

        if ($lookup->found) {
            return $this->recordLocally($attempt, $actor, null, null, $lookup->status);
        }

        return $this->database->transaction(function () use ($attempt, $actor): CourierBookingAttempt {
            /** @var CourierBookingAttempt $locked */
            $locked = CourierBookingAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($locked->status !== CourierBookingStatus::Uncertain) {
                return $locked;
            }

            $locked->forceFill([
                'status' => CourierBookingStatus::Failed,
                'failure_code' => 'not_found_at_provider',
                'failure_message' => __('courier.booking.not_found_message'),
                'completed_at' => now(),
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'courier.booking_reconciled_not_found',
                actorId: $actor?->id,
                auditableType: CourierBookingAttempt::class,
                auditableId: $locked->id,
                after: ['status' => 'failed'],
                module: 'courier',
            ));

            return $locked;
        });
    }

    protected function recordLocally(CourierBookingAttempt $attempt, ?User $actor, ?string $consignmentId, ?string $trackingCode, ?string $rawStatus): CourierBookingAttempt
    {
        try {
            $this->record->handle($attempt, $actor, $consignmentId, $trackingCode, $rawStatus);
        } catch (Throwable $exception) {
            report($exception);

            return $this->stillUncertain($attempt, __('courier.booking.uncertain_message'));
        }

        return $attempt->refresh();
    }

    protected function stillUncertain(CourierBookingAttempt $attempt, string $message): CourierBookingAttempt
    {
        $attempt->forceFill(['failure_message' => mb_substr($message, 0, 255)])->save();

        return $attempt;
    }
}
