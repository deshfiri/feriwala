<?php

namespace App\Domain\Referral\Jobs;

use App\Domain\Referral\Actions\ReleaseReferralCommission;
use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Models\ReferralCommission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pay what one qualifying event made payable straight away (D24, P7-43).
 *
 * Dispatched after the event's transaction commits. A commission with a
 * holding period is not due yet and is left for the scheduled sweep; the
 * release itself re-reads each row under a lock, so this job running twice, or
 * beside the sweep, pays nothing twice.
 */
class ReleaseReferralCommissions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $eventId,
    ) {}

    public function handle(ReleaseReferralCommission $release): void
    {
        ReferralCommission::query()
            ->where('referral_qualifying_event_id', $this->eventId)
            ->where('status', CommissionStatus::Pending->value)
            ->orderBy('level')
            ->pluck('id')
            ->each(fn (int $id) => $release->handle($id));
    }
}
