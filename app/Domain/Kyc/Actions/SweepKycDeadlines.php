<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycDeadlines;
use App\Domain\Kyc\Models\KycDeadlineEvent;
use App\Domain\Kyc\Models\KycSubmission;
use App\Notifications\Kyc\KycDeadlineApproaching;
use Carbon\CarbonImmutable;
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
            ->where('deadline_at', '>', $this->now())
            ->where('deadline_at', '<=', $this->now()->addDays($days))
            ->whereDoesntHave('deadlineEvents', fn ($event) => $event
                ->where('event', KycDeadlineEvent::WARNED))
            ->with('businessAccount.owner')
            ->chunkById(200, function ($submissions) use (&$warned) {
                foreach ($submissions as $submission) {
                    // The claim comes before the message. A retry overlapping a
                    // slow first pass loses at the unique index rather than
                    // sending a second warning.
                    $claim = KycDeadlineEvent::claim($submission, KycDeadlineEvent::WARNED, [
                        'deadline_at' => $submission->deadline_at,
                    ]);

                    if ($claim === null) {
                        continue;
                    }

                    $owner = $submission->businessAccount?->owner;

                    if ($owner === null) {
                        continue;
                    }

                    $remaining = (int) ceil(
                        $this->now()->diffInDays($submission->deadline_at, absolute: true)
                    );

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
            ->where('deadline_at', '<=', $this->now())
            ->whereDoesntHave('deadlineEvents', fn ($event) => $event
                ->where('event', KycDeadlineEvent::ENFORCED))
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
     * Now, in the platform's timezone (§7.4).
     *
     * Stated rather than inherited. "Due on the 30th" has to mean the 30th
     * where the account holder is, and a server rebuilt in another region must
     * not silently move every deadline.
     */
    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'));
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
