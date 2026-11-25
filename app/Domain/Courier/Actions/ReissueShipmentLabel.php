<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Courier\Exceptions\ShipmentLabelRefused;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Courier\Queries\ShipmentLabelSnapshot;
use App\Models\User;
use App\Support\Money\Money;
use DateTimeInterface;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * "Regenerate corrected label": a deliberate new version, never an edit.
 *
 * The new version is built exactly as the first was, from the same frozen
 * sources -- so it differs only where one of those was corrected through its
 * own audited workflow: a courier reference recorded by hand, or a
 * warehouse's return details put right. The recipient and address cannot
 * differ while a booking is live, because D38 refuses that change; a parcel
 * that must go elsewhere is cancelled and booked again, which is a new
 * shipment with a label of its own.
 *
 * A reason is required, the version replaced keeps every column and gains only
 * when and why it was superseded, and a version identical to the current one is
 * refused rather than recorded. The courier is never called.
 */
class ReissueShipmentLabel
{
    /** Columns that do not make one version differ from another. */
    protected const META = ['id', 'public_id', 'version', 'issued_by', 'issued_at', 'issue_reason', 'superseded_at', 'superseded_reason', 'superseded_by_label_id'];

    public function __construct(
        protected DatabaseManager $database,
        protected ShipmentLabelSnapshot $snapshot,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws InvalidArgumentException when no reason is given
     * @throws ShipmentLabelRefused
     */
    public function handle(Shipment $shipment, User $actor, string $reason): ShipmentLabel
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        $attributes = $this->snapshot->build($shipment->refresh());

        return $this->database->transaction(function () use ($shipment, $actor, $reason, $attributes): ShipmentLabel {
            /** @var Shipment $locked */
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            /** @var ShipmentLabel|null $current */
            $current = ShipmentLabel::query()->current()->where('shipment_id', $locked->id)->first();

            if ($current === null) {
                throw ShipmentLabelRefused::because('no_current_label');
            }

            if (! $this->differs($current, $attributes)) {
                throw ShipmentLabelRefused::because('nothing_changed');
            }

            // Superseded first: the one-current index admits the new version only then.
            $replacement = new ShipmentLabel([
                ...$attributes,
                'version' => $current->version + 1,
                'issued_by' => $actor->id,
                'issued_at' => now(),
                'issue_reason' => $reason,
            ]);

            $current->forceFill([
                'superseded_at' => now(),
                'superseded_reason' => $reason,
            ]);

            // The old row must stop being current before the one-current index
            // admits the new one, yet it names the new one: its id is taken
            // first, and the foreign key is checked at commit.
            $current->superseded_by_label_id = $this->nextLabelId();
            $current->save();
            $replacement->id = $current->superseded_by_label_id;
            $replacement->save();

            $this->audit->handle(new AuditEntry(
                action: 'shipment.label_reissued',
                actorId: $actor->id,
                auditableType: Shipment::class,
                auditableId: $locked->id,
                before: ['version' => $current->version],
                after: ['shipment' => $locked->reference, 'version' => $replacement->version, 'changed' => $this->changedFields($current, $attributes)],
                reason: $reason,
                module: 'courier',
            ));

            return $replacement;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function differs(ShipmentLabel $current, array $attributes): bool
    {
        return $this->changedFields($current, $attributes) !== [];
    }

    /**
     * Which columns the new version changes -- names only, never values.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    protected function changedFields(ShipmentLabel $current, array $attributes): array
    {
        $changed = [];

        foreach ($attributes as $column => $value) {
            if (in_array($column, self::META, true)) {
                continue;
            }

            $existing = $current->getAttribute($column);

            $same = match (true) {
                $value instanceof Money && $existing instanceof Money => $value->equals($existing),
                $value instanceof DateTimeInterface && $existing instanceof DateTimeInterface => $value->getTimestamp() === $existing->getTimestamp(),
                default => $value == $existing,
            };

            if (! $same) {
                $changed[] = $column;
            }
        }

        return $changed;
    }

    protected function nextLabelId(): int
    {
        return (int) $this->database->selectOne("SELECT nextval(pg_get_serial_sequence('shipment_labels', 'id')) AS id")->id;
    }
}
