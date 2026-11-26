<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Courier\Exceptions\ShipmentLabelRefused;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Courier\Queries\ShipmentLabelSnapshot;
use App\Domain\Notification\Actions\AnnounceCourierEvent;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Freezes a parcel's first shipping label, once the courier consignment exists.
 *
 * **Idempotent.** A shipment has at most one current label (a partial unique
 * index), and this returns it if it is there -- so a retry, a repeated webhook,
 * a poll or a second click never makes a second one.
 *
 * **Never books, never moves anything.** It reads Banij's own records only: no
 * courier is called, and neither the shipment's nor the order's status is
 * touched. A refusal is remembered on the shipment as a safe code -- staff are
 * told when a person has to fix something -- and nothing about the consignment
 * is undone.
 *
 * Audited with references only: no name, phone or address enters the audit
 * log, and the owning Partner is told the label is ready without either.
 */
class IssueShipmentLabel
{
    public function __construct(
        protected DatabaseManager $database,
        protected ShipmentLabelSnapshot $snapshot,
        protected RecordAuditLog $audit,
        protected AnnounceCourierEvent $announce,
    ) {}

    /**
     * `$reason` says how the label came about when that is not the ordinary
     * course — the legacy reconciliation records that it reconstructed it, and
     * that the Storefront branding is today's, not necessarily what shipped.
     *
     * @throws ShipmentLabelRefused
     */
    public function handle(Shipment $shipment, ?User $actor, ?string $reason = null): ShipmentLabel
    {
        $current = $this->current($shipment);

        if ($current !== null) {
            return $current;
        }

        try {
            $attributes = $this->snapshot->build($shipment);
        } catch (ShipmentLabelRefused $refused) {
            $this->block($shipment, $refused);

            throw $refused;
        }

        try {
            return $this->database->transaction(function () use ($shipment, $actor, $attributes, $reason): ShipmentLabel {
                /** @var Shipment $locked */
                $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

                $current = $this->current($locked);

                if ($current !== null) {
                    return $current;
                }

                $label = ShipmentLabel::query()->create([
                    ...$attributes,
                    'version' => 1,
                    'issued_by' => $actor?->id,
                    'issued_at' => now(),
                    'issue_reason' => $reason,
                ]);

                // The warehouse becomes part of the shipment's record the
                // first time it is known; afterwards the database refuses a change.
                $locked->forceFill([
                    'warehouse_id' => $locked->warehouse_id ?? $label->warehouse_id,
                    'label_blocker' => null,
                ])->save();

                $this->audit->handle(new AuditEntry(
                    action: 'shipment.label_issued',
                    actorId: $actor?->id,
                    auditableType: Shipment::class,
                    auditableId: $locked->id,
                    after: ['shipment' => $locked->reference, 'version' => 1, 'warehouse' => $label->warehouse_code],
                    module: 'courier',
                ));

                $this->announce->labelReady($locked->load('order'));

                return $label;
            });
        } catch (UniqueConstraintViolationException) {
            // Issued by a racing call between the check and the insert.
            return $this->current($shipment) ?? throw ShipmentLabelRefused::because('issue_conflict');
        }
    }

    protected function current(Shipment $shipment): ?ShipmentLabel
    {
        /** @var ShipmentLabel|null $label */
        $label = ShipmentLabel::query()->current()->where('shipment_id', $shipment->id)->first();

        return $label;
    }

    /**
     * Remember why, and -- when it is something a person must fix -- tell the
     * staff who can, once per shipment and reason.
     */
    protected function block(Shipment $shipment, ShipmentLabelRefused $refused): void
    {
        if ($refused->reason === 'not_storefront_order') {
            return;
        }

        $changed = Shipment::query()
            ->whereKey($shipment->id)
            ->where(fn ($query) => $query->whereNull('label_blocker')->orWhere('label_blocker', '!=', $refused->reason))
            ->update(['label_blocker' => $refused->reason]);

        $shipment->label_blocker = $refused->reason;

        if ($changed > 0 && $refused->needsStaff()) {
            $this->announce->labelBlocked($shipment->loadMissing('order'), $refused->reason);
        }
    }
}
