<?php

namespace App\Domain\Referral\Actions;

use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Enums\ReversalCause;
use App\Domain\Referral\Exceptions\ReferralRefused;
use App\Domain\Referral\Models\ReferralCommission;
use Carbon\CarbonImmutable;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * The scheduler's half of the outbox (D24, P7-43).
 *
 * Pays every pending commission whose holding period has passed, and tries
 * again every reversal that was owed because a wallet could not carry it. Each
 * row on its own: one that cannot be settled is logged and left for the next
 * run, and the rest go ahead.
 */
class ReleaseDueReferralCommissions
{
    public function __construct(
        protected ReleaseReferralCommission $release,
        protected ReverseReferralCommission $reverse,
        protected LogManager $log,
    ) {}

    /**
     * @return array{released: int, recovered: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $released = 0;
        $recovered = 0;

        ReferralCommission::query()
            ->due($now)
            ->orderBy('available_at')
            ->limit(500)
            ->pluck('id')
            ->each(function (int $id) use ($now, &$released) {
                try {
                    if ($this->release->handle($id, $now) !== null) {
                        $released++;
                    }
                } catch (Throwable $throwable) {
                    $this->log->channel('wallet')->error('A referral commission could not be released', ['commission' => $id, 'error' => $throwable->getMessage()]);
                }
            });

        ReferralCommission::query()
            ->where('status', CommissionStatus::ReversalOwed->value)
            ->orderBy('reversed_at')
            ->limit(500)
            ->get()
            ->each(function (ReferralCommission $commission) use (&$recovered) {
                try {
                    $this->reverse->handle($commission, $commission->reversal_cause ?? ReversalCause::Manual, (string) $commission->reversal_reason);

                    if ($commission->status === CommissionStatus::Reversed) {
                        $recovered++;
                    }
                } catch (ReferralRefused) {
                    // Settled by someone else in the meantime.
                } catch (Throwable $throwable) {
                    $this->log->channel('wallet')->error('An owed referral reversal could not be posted', ['commission' => $commission->public_id, 'error' => $throwable->getMessage()]);
                }
            });

        return ['released' => $released, 'recovered' => $recovered];
    }
}
