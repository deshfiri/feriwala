<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Kyc\Models\KycSubmissionRequirement;
use App\Domain\Package\Enums\UserPackageStatus;

/**
 * Records what a round is being asked for, at the moment it opens (§7.2).
 *
 * Every path that creates a round calls this — the applicant opening a draft,
 * a resubmission after corrections, an administrator requesting an update.
 * Without it, editing a document type retroactively changes what an already
 * submitted round was judged on: a reviewer reads "passport required" beside a
 * submission made when it was optional, and an applicant who complied is
 * suddenly shown as incomplete.
 *
 * Idempotent. A round that already has requirements keeps them, so reopening
 * the form cannot quietly re-snapshot against configuration that has changed
 * since — which is exactly the rewrite this exists to prevent.
 */
class CaptureRoundRequirements
{
    /**
     * @return int how many requirements were captured (zero when already done)
     */
    public function handle(KycSubmission $submission, ?string $packageId = null): int
    {
        if ($submission->requirements()->exists()) {
            return 0;
        }

        $account = $submission->businessAccount;
        $packageId ??= $this->packageIdFor($submission);
        $country = $account?->owner?->country;

        $rows = [];

        foreach (KycDocumentType::query()->active()->with('scopes')->get() as $type) {
            if (! $type->appliesTo($packageId, $country)) {
                continue;
            }

            $rows[] = KycSubmissionRequirement::snapshotOf(
                $type,
                // The scope may make it mandatory here and optional elsewhere,
                // so the round records what *this* applicant was asked for.
                $type->isRequiredFor($packageId, $country),
            );
        }

        foreach ($rows as $row) {
            $submission->requirements()->create($row);
        }

        return count($rows);
    }

    /**
     * The package the account is on, or has chosen and not yet paid for.
     *
     * A pending subscription counts: §7.2 scopes requirements by package, and
     * the applicant picks their package before completing KYC, so ignoring the
     * unpaid choice would ask them for the wrong documents at exactly the point
     * the scoping is supposed to help.
     */
    protected function packageIdFor(KycSubmission $submission): ?string
    {
        $account = $submission->businessAccount;

        if ($account === null) {
            return null;
        }

        $subscription = $account->packages()
            ->whereIn('status', [UserPackageStatus::Active, UserPackageStatus::PendingPayment])
            ->with('package')
            ->latest('id')
            ->first();

        // The immutable id, not the slug: a scope rule must survive a URL
        // being renamed.
        return $subscription?->package?->public_id;
    }
}
