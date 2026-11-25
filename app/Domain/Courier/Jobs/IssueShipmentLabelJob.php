<?php

namespace App\Domain\Courier\Jobs;

use App\Domain\Courier\Actions\IssueShipmentLabel;
use App\Domain\Courier\Exceptions\ShipmentLabelRefused;
use App\Domain\Courier\Models\Shipment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Issues a parcel's label once its shipment -- and the consignment behind it --
 * has been committed.
 *
 * Dispatched after commit, so it only ever sees a shipment that exists, and
 * kept apart from the booking: a label that cannot be issued (or a renderer
 * that breaks) never rolls back, cancels or re-books a consignment, because by
 * the time this runs there is nothing left of the booking to undo. Every
 * failure is recorded or reported here and goes no further -- on the `sync`
 * driver this runs inside the dispatcher's after-commit callback, where an
 * exception would otherwise surface in the booking's own code.
 *
 * Unique per shipment, and the action it runs is idempotent besides.
 */
class IssueShipmentLabelJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $shipmentId) {}

    public function uniqueId(): string
    {
        return (string) $this->shipmentId;
    }

    public function handle(IssueShipmentLabel $issue): void
    {
        try {
            $shipment = Shipment::query()->find($this->shipmentId);

            if ($shipment !== null) {
                $issue->handle($shipment, null);
            }
        } catch (ShipmentLabelRefused) {
            // Recorded on the shipment by the action; staff were told if they must act.
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
