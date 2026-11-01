<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Courier\Data\SteadfastStatusApplied;
use App\Domain\Courier\Enums\ShipmentStatusChangeSource;
use App\Domain\Courier\Events\SteadfastStatusNeedsReview;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Integrations\Courier\Steadfast\SteadfastStatus;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * The one place a Steadfast status reaches Banij's records -- whether it came
 * from the webhook, the scheduled poll or a staff "check now". Nothing updates
 * an order or shipment column directly: every courier move goes through
 * {@see RecordManualCourierStatusUpdate}, the same provider-aware action manual
 * updates use, so the transition map and the order's own status actions decide
 * what is legal.
 *
 * **Idempotent and race-safe.** Under a row lock on the shipment, a status equal
 * to the one already held changes nothing; so a webhook and a poll carrying the
 * same status produce one effect, one history entry, and one set of downstream
 * actions (stock sold, Partner profit -- both of which are exactly-once on their
 * own as well).
 *
 * What it will not do: invent a state. An unrecognised value is stored as
 * received and the shipment flagged for review; a value that needs a move the
 * map does not allow (a parcel already in transit that Steadfast reports
 * cancelled) is flagged, not forced; partial delivery moves nothing. None of
 * these touch stock, COD or wallets. COD collection is not recorded here:
 * "delivered" is not "cash collected".
 */
class ApplySteadfastStatus
{
    public function __construct(
        protected DatabaseManager $database,
        protected RecordManualCourierStatusUpdate $record,
    ) {}

    public function handle(Shipment $shipment, string $raw, ShipmentStatusChangeSource $source): SteadfastStatusApplied
    {
        $known = SteadfastStatus::fromRaw($raw);

        return $this->database->transaction(function () use ($shipment, $raw, $source, $known): SteadfastStatusApplied {
            /** @var Shipment $locked */
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            if ($locked->provider_status === $raw) {
                $locked->forceFill(['provider_synced_at' => now(), 'provider_sync_error' => null])->save();

                return SteadfastStatusApplied::unchanged($known);
            }

            $review = $known === null || $known->requiresReview();
            $moved = false;
            $target = $known?->courierTarget();

            if ($target !== null) {
                $path = $this->path($locked->status, $target);

                if ($path === null) {
                    // No legal route from where the shipment is. Left alone and flagged.
                    $review = true;
                } else {
                    try {
                        $this->database->transaction(function () use ($locked, $path, $raw, $source): void {
                            foreach ($path as $step) {
                                $this->record->handle(
                                    $locked,
                                    $step,
                                    null,
                                    $step === OrderCourierStatus::Cancelled
                                        ? __('steadfast.status.cancelled_at_steadfast')
                                        : __('steadfast.status.implied', ['status' => $raw]),
                                    $source,
                                );
                            }
                        });

                        $moved = $path !== [];
                    } catch (Throwable $exception) {
                        // The whole walk is rolled back together: never half-way.
                        report($exception);
                        $review = true;
                    }
                }
            }

            $locked->refresh();

            $wasFlagged = (bool) $locked->needs_review;

            $locked->forceFill([
                'provider_status' => $raw,
                'provider_status_at' => now(),
                'provider_synced_at' => now(),
                'provider_sync_error' => null,
                'needs_review' => $wasFlagged || $review,
            ])->save();

            $locked->trackingEvents()->create([
                'event_code' => 'steadfast.'.$raw,
                'description' => null,
                'occurred_at' => now(),
                'source' => $source->value,
            ]);

            $newlyFlagged = $review && ! $wasFlagged;

            if ($newlyFlagged) {
                $shipmentId = $locked->id;

                $this->database->afterCommit(fn () => SteadfastStatusNeedsReview::dispatch($shipmentId, $raw, $known === null));
            }

            return new SteadfastStatusApplied(
                changed: true,
                status: $known,
                unknown: $known === null,
                newlyFlagged: $newlyFlagged,
                courierMoved: $moved,
            );
        });
    }

    /**
     * The shortest run of legal courier moves from one status to another, or
     * null when there is none. Failure, return and cancellation are only ever
     * the destination, never a stop on the way.
     *
     * @return array<int, OrderCourierStatus>|null
     */
    protected function path(OrderCourierStatus $from, OrderCourierStatus $to): ?array
    {
        if ($from === $to) {
            return [];
        }

        $never = [
            OrderCourierStatus::FailedDelivery,
            OrderCourierStatus::ReturnedToOrigin,
            OrderCourierStatus::Cancelled,
            OrderCourierStatus::Delivered,
        ];

        $queue = [[$from, []]];
        $seen = [$from->value => true];

        while ($queue !== []) {
            [$state, $steps] = array_shift($queue);

            foreach ($state->transitionsTo() as $next) {
                if (isset($seen[$next->value])) {
                    continue;
                }

                $seen[$next->value] = true;

                if ($next === $to) {
                    return [...$steps, $next];
                }

                if (! in_array($next, $never, true)) {
                    $queue[] = [$next, [...$steps, $next]];
                }
            }
        }

        return null;
    }
}
