<?php

namespace App\Domain\Kyc\Batch;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Actions\CaptureRoundRequirements;
use App\Domain\Kyc\Actions\PublishKycBatchVersion;
use App\Domain\Kyc\Enums\KycAudience;
use App\Domain\Kyc\Exceptions\InvalidKycBatch;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use App\Models\User;
use InvalidArgumentException;

/**
 * Pins a KYC round to the batch version it will be asked against (D30).
 *
 * Which batch, in order: the one staff chose when opening the round; otherwise
 * the audience's default batch, if it applies to this applicant; otherwise none —
 * and a round with none keeps the flow it always had. Nothing changes for an
 * installation that has not made a batch the default.
 *
 * The newest *published* version is pinned, and from then on that version is the
 * round. A batch carried over from before versions existed is published the first
 * time a round needs it, from its unchanged structure.
 *
 * A round that has begun — any evidence or any choice made — cannot be moved to
 * another batch: that would orphan what the applicant already gave.
 */
class AssignKycBatch
{
    public function __construct(
        protected PublishKycBatchVersion $publisher,
        protected CaptureRoundRequirements $packages,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @return bool whether the round was pinned to a batch
     *
     * @throws InvalidArgumentException
     * @throws InvalidKycBatch when the batch could never be completed as it stands
     */
    public function forPartner(KycSubmission $round, ?KycBatch $explicit = null, ?User $by = null): bool
    {
        $account = $round->businessAccount;

        $batch = $explicit ?? $this->defaultFor(
            KycAudience::Account,
            $account === null ? null : $this->packages->packageIdForAccount($account),
            $account?->owner?->country,
        );

        if ($batch === null) {
            return false;
        }

        $this->guard($round->documents()->exists() || $round->fields()->exists() || $round->selections !== null, $batch, KycAudience::Account);

        $this->pin($round, $batch, $explicit !== null, $by, 'kyc.batch_assigned', $account?->id);

        return true;
    }

    /**
     * @throws InvalidArgumentException
     * @throws InvalidKycBatch when the batch could never be completed as it stands
     */
    public function forSupplier(SupplierKycSubmission $round, ?KycBatch $explicit = null, ?User $by = null): bool
    {
        $batch = $explicit ?? $this->defaultFor(KycAudience::Supplier);

        if ($batch === null) {
            return false;
        }

        $this->guard($round->documents()->exists() || $round->fields()->exists() || $round->selections !== null, $batch, KycAudience::Supplier);

        $this->pin($round, $batch, $explicit !== null, $by, 'supplier_kyc.batch_assigned', null);

        return true;
    }

    /**
     * The default batch for an audience that applies to this applicant: the exact
     * audience's default first, then the one for both.
     */
    public function defaultFor(KycAudience $audience, ?string $package = null, ?string $country = null): ?KycBatch
    {
        foreach ([$audience, KycAudience::Both] as $candidate) {
            $batch = KycBatch::query()
                ->available()
                ->where('audience', $candidate->value)
                ->where('is_default', true)
                ->with('scopes')
                ->first();

            // Country and package rules are a Partner notion; a Supplier has
            // neither, so for them a default simply applies.
            if ($batch !== null && ($audience === KycAudience::Supplier || $batch->appliesTo($package, $country))) {
                return $batch;
            }
        }

        return null;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function guard(bool $begun, KycBatch $batch, KycAudience $audience): void
    {
        if ($batch->isArchived() || ! $batch->is_active) {
            throw new InvalidArgumentException(__('kyc.batch.refused.batch_unavailable'));
        }

        // A batch for suppliers is never put in front of a Partner, or the
        // other way round. "Both" serves either.
        if (! in_array($batch->audience, [$audience, KycAudience::Both], true)) {
            throw new InvalidArgumentException(__('kyc.batch.refused.wrong_audience'));
        }

        if ($begun) {
            throw new InvalidArgumentException(__('kyc.batch.refused.round_begun'));
        }
    }

    private function pin(KycSubmission|SupplierKycSubmission $round, KycBatch $batch, bool $explicit, ?User $by, string $action, ?int $accountId): void
    {
        $version = $this->publisher->latestOrPublish($batch);

        $round->forceFill([
            'kyc_batch_id' => $batch->id,
            'kyc_batch_version_id' => $version->id,
        ])->save();

        // Which batch and version, and why — never anything the applicant gave.
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $by?->id,
            actorType: $by === null ? 'system' : 'user',
            auditableType: $round::class,
            auditableId: $round->id,
            after: [
                'batch' => $batch->key,
                'version' => $version->version,
                'source' => $explicit ? 'chosen' : 'default',
            ],
            accountId: $accountId,
            module: 'kyc',
        ));
    }
}
