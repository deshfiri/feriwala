<?php

namespace App\Domain\Courier\Actions;

use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Integrations\Courier\Steadfast\SteadfastException;
use App\Integrations\Courier\Steadfast\SteadfastFailureKind;
use App\Integrations\Courier\Steadfast\SteadfastStatus;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * The scheduled sweep: one bounded batch of Steadfast parcels that are still
 * moving, oldest check first.
 *
 * Bounded three ways so it can never become a scan of history or a hammer on
 * Steadfast: a batch size; only parcels whose own shipment is not terminal and
 * whose Steadfast status is not final; and a wait between checks of one parcel
 * -- longer after a failed check -- read from `courier.steadfast.poll`. One
 * parcel's failure does not stop the others, except a rejected key or a missing
 * configuration, which would fail every parcel the same way.
 *
 * Does nothing at all while Steadfast is switched off.
 */
class SynchroniseSteadfastShipments
{
    public function __construct(
        protected SynchroniseSteadfastShipment $synchronise,
    ) {}

    /**
     * @return array{checked: int, changed: int, failed: int}
     */
    public function handle(?int $limit = null): array
    {
        $summary = ['checked' => 0, 'changed' => 0, 'failed' => 0];

        $provider = CourierProvider::query()->where('code', CourierProviderCode::Steadfast->value)->first();

        if ($provider === null || ! $provider->is_enabled) {
            return $summary;
        }

        $limit ??= (int) config('courier.steadfast.poll.batch', 50);

        foreach ($this->due($provider->id, $limit) as $shipment) {
            $summary['checked']++;

            try {
                $applied = $this->synchronise->handle($shipment);

                if ($applied?->changed) {
                    $summary['changed']++;
                }
            } catch (SteadfastException $exception) {
                $summary['failed']++;

                if (in_array($exception->kind, [SteadfastFailureKind::Authentication, SteadfastFailureKind::NotConfigured], true)) {
                    break;
                }
            }
        }

        return $summary;
    }

    /**
     * @return iterable<int, Shipment>
     */
    protected function due(int $providerId, int $limit): iterable
    {
        $interval = (int) config('courier.steadfast.poll.interval', 600);
        $errorInterval = (int) config('courier.steadfast.poll.error_interval', 1800);

        return Shipment::query()
            ->where('courier_provider_id', $providerId)
            ->whereNotIn('status', array_map(fn (OrderCourierStatus $status) => $status->value, array_filter(
                OrderCourierStatus::cases(),
                fn (OrderCourierStatus $status) => $status->isTerminal(),
            )))
            ->where(fn (Builder $query) => $query
                ->whereNull('provider_status')
                ->orWhereNotIn('provider_status', SteadfastStatus::finalValues()))
            ->where(fn (Builder $query) => $query
                ->whereNull('provider_synced_at')
                ->orWhere(fn (Builder $due) => $due
                    ->whereNull('provider_sync_error')
                    ->where('provider_synced_at', '<', now()->subSeconds($interval)))
                ->orWhere(fn (Builder $due) => $due
                    ->whereNotNull('provider_sync_error')
                    ->where('provider_synced_at', '<', now()->subSeconds($errorInterval))))
            ->orderByRaw('provider_synced_at asc nulls first')
            ->limit($limit)
            ->get();
    }
}
