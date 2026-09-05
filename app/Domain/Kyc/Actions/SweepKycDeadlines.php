<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycDeadlines;
use App\Domain\Kyc\Models\KycSubmission;
use App\Notifications\Kyc\KycDeadlineApproaching;
use Illuminate\Database\Eloquent\Builder;

/**
 * The daily §7.4 pass: warn what is due, act on what is overdue.
 *
 * Two jobs rather than one, because they answer to different settings and a
 * deployment may want warnings without restrictions. Both are skipped entirely
 * when no deadline is configured — see {@see KycDeadlines}, where the absence
 * of a configured window means there is no deadline at all.
 *
 * Chunked. A daily sweep over every submission ever made would load the table
 * into memory on a platform of any size (§39).
 */
class SweepKycDeadlines
{
    /**
     * Rounds still waiting on the applicant.
     *
     * A submission sitting in the review queue is waiting on **us**, so its
     * deadline is not the applicant's to miss — §7.4 is about the applicant
     * failing to complete, not about how long a reviewer takes.
     */
    private const APPLICANT_OWES = [
        KycStatus::Draft,
        KycStatus::ResubmissionRequired,
        KycStatus::Rejected,
    ];

    public function __construct(
        protected KycDeadlines $deadlines,
        protected EnforceKycDeadline $enforce,
    ) {}

    /**
     * @return array{warned: int, enforced: int}
     */
    public function handle(): array
    {
        if (! $this->deadlines->isEnabled()) {
            return ['warned' => 0, 'enforced' => 0];
        }

        return [
            'warned' => $this->warn(),
            'enforced' => $this->enforce(),
        ];
    }

    /**
     * Tell people whose deadline is close, once.
     */
    protected function warn(): int
    {
        $days = $this->deadlines->warningDays();

        if ($days === null) {
            return 0;
        }

        $warned = 0;

        $this->pending()
            ->whereNull('deadline_warned_at')
            ->where('deadline_at', '>', now())
            ->where('deadline_at', '<=', now()->addDays($days))
            ->with('businessAccount.owner')
            ->chunkById(200, function ($submissions) use (&$warned) {
                foreach ($submissions as $submission) {
                    $owner = $submission->businessAccount?->owner;

                    // Stamped either way. Without an owner there is nobody to
                    // warn, and leaving it unstamped would retry every day.
                    $submission->forceFill(['deadline_warned_at' => now()])->save();

                    if ($owner === null) {
                        continue;
                    }

                    $remaining = (int) ceil(now()->diffInDays($submission->deadline_at, absolute: true));

                    $owner->notify(new KycDeadlineApproaching(max($remaining, 1)));
                    $warned++;
                }
            });

        return $warned;
    }

    protected function enforce(): int
    {
        $enforced = 0;

        $this->pending()
            ->whereNull('deadline_enforced_at')
            ->where('deadline_at', '<=', now())
            ->chunkById(200, function ($submissions) use (&$enforced) {
                foreach ($submissions as $submission) {
                    if ($this->enforce->handle($submission)) {
                        $enforced++;
                    }
                }
            });

        return $enforced;
    }

    /**
     * @return Builder<KycSubmission>
     */
    protected function pending(): Builder
    {
        return KycSubmission::query()
            ->whereNotNull('deadline_at')
            ->whereIn('status', self::APPLICANT_OWES);
    }
}
