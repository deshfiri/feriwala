<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Exceptions\CourierReferencesRefused;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * A person records the courier's own references for a parcel Banij holds
 * without them -- read from the courier's merchant panel, typed in here.
 *
 * The gap this closes: a Steadfast booking recovered by invoice lookup alone
 * (reconciliation, or a booking whose reply was lost) is confirmed to exist,
 * but `status_by_invoice` returns neither Steadfast's consignment id nor its
 * tracking code, and nothing else ever would. A manual shipment raised before
 * its tracking number was known has the same hole.
 *
 * **Fills blanks only.** A reference already recorded is never overwritten --
 * a wrong one is a matter for the courier panel and a new booking, not an
 * edit here. **Never calls the courier**: this records what a person read, it
 * does not ask, book or change anything at the provider. Audited with the
 * person and their reason.
 */
class RecordShipmentCourierReferences
{
    public function __construct(
        protected DatabaseManager $database,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws InvalidArgumentException when no reason, or nothing to record, is given
     * @throws CourierReferencesRefused
     */
    public function handle(Shipment $shipment, User $actor, ?string $consignmentId, ?string $trackingCode, string $reason): Shipment
    {
        $reason = trim($reason);
        $consignmentId = $this->clean($consignmentId);
        $trackingCode = $this->clean($trackingCode);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        if ($consignmentId === null && $trackingCode === null) {
            throw CourierReferencesRefused::because('nothing_to_record');
        }

        try {
            return $this->database->transaction(function () use ($shipment, $actor, $consignmentId, $trackingCode, $reason): Shipment {
                /** @var Shipment $locked */
                $locked = Shipment::query()->with('courierProvider')->lockForUpdate()->findOrFail($shipment->id);

                if ($locked->status === OrderCourierStatus::Cancelled) {
                    throw CourierReferencesRefused::because('cancelled');
                }

                // Only a provider with its own consignment identifier has one to record.
                if ($consignmentId !== null && $locked->courierProvider->code !== CourierProviderCode::Steadfast) {
                    throw CourierReferencesRefused::because('no_consignment_for_provider');
                }

                if (($consignmentId !== null && $locked->external_id !== null)
                    || ($trackingCode !== null && $locked->tracking_number !== null)) {
                    throw CourierReferencesRefused::because('already_recorded');
                }

                $recorded = array_filter([
                    'external_id' => $consignmentId,
                    'tracking_number' => $trackingCode,
                ], fn (?string $value) => $value !== null);

                $locked->forceFill($recorded)->save();

                $this->audit->handle(new AuditEntry(
                    action: 'shipment.courier_references_recorded',
                    actorId: $actor->id,
                    auditableType: Shipment::class,
                    auditableId: $locked->id,
                    // The courier's own identifiers: neither names, reaches nor
                    // locates the customer.
                    after: ['shipment' => $locked->reference] + $recorded,
                    reason: $reason,
                    module: 'courier',
                ));

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            // Another shipment already carries that consignment id.
            throw CourierReferencesRefused::because('consignment_in_use');
        }
    }

    protected function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
