<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Kyc\Models\KycSubmissionRequirement;
use App\Domain\Package\Enums\UserPackageStatus;
use Illuminate\Support\Collection;

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
     * @param  array<int, string>|null  $onlyTypeIds  public ids to narrow the round
     *                                                to; null asks for everything
     *                                                that applies
     * @return int how many requirements were captured (zero when already done)
     */
    public function handle(
        KycSubmission $submission,
        ?string $packageId = null,
        ?array $onlyTypeIds = null,
    ): int {
        if ($submission->requirements()->exists()) {
            return 0;
        }

        $account = $submission->businessAccount;
        $packageId ??= $this->packageIdFor($submission);
        $country = $account?->owner?->country;

        $types = $this->applicableFor($packageId, $country);

        /*
         * A narrowed round asks for a subset, never for something outside the
         * scope rules. §7.2 requests fresh copies of documents this account is
         * already subject to; asking for one the rules say does not apply here
         * would be a scope change made through the back door, and the applicant
         * would have no way to tell which rule they were failing.
         */
        if ($onlyTypeIds !== null) {
            $wanted = array_flip($onlyTypeIds);

            $types = $types->filter(
                fn (KycDocumentType $type) => isset($wanted[$type->public_id]),
            );
        }

        foreach ($types as $type) {
            $submission->requirements()->create(
                KycSubmissionRequirement::snapshotOf(
                    $type,
                    // The scope may make it mandatory here and optional
                    // elsewhere, so the round records what *this* applicant was
                    // asked for.
                    $type->isRequiredFor($packageId, $country),
                ),
            );
        }

        return $types->count();
    }

    /**
     * The active document types that apply to a given package and country.
     *
     * Public because the screen offering a document selection has to show the
     * same list this captures from. Two implementations of "which documents
     * apply here" would disagree the first time a scope rule changed, and the
     * disagreement would surface as an administrator selecting a document that
     * silently never got asked for.
     *
     * @return Collection<int, KycDocumentType>
     */
    public function applicableFor(?string $packageId, ?string $country): Collection
    {
        return KycDocumentType::query()
            ->active()
            ->with('scopes')
            ->get()
            ->filter(fn (KycDocumentType $type) => $type->appliesTo($packageId, $country))
            ->values();
    }

    /**
     * The package scoping an account's requirements, for callers that hold an
     * account rather than a round.
     */
    public function packageIdForAccount(BusinessAccount $account): ?string
    {
        $subscription = $account->packages()
            ->whereIn('status', [UserPackageStatus::Active, UserPackageStatus::PendingPayment])
            ->with('package')
            ->latest('id')
            ->first();

        return $subscription?->package?->public_id;
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

        // The immutable id, not the slug: a scope rule must survive a URL
        // being renamed.
        return $account === null ? null : $this->packageIdForAccount($account);
    }
}
