<?php

namespace App\Domain\Cases\Actions;

use App\Domain\Cases\Exceptions\CaseEvidenceRefused;
use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Models\OrderReturn;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Make a Partner's unsent attachments part of the request they have just sent
 * (D35).
 *
 * Only files that are the sender's own drafts, for this case's own order, and not
 * yet on a case are taken; anything else — another Partner's file, one already
 * sent, one for a different order — is refused and nothing is attached. Call
 * inside the transaction that opened the case, so the request and its evidence
 * arrive together or not at all.
 */
class AttachDraftEvidence
{
    /**
     * @param  array<int, string>  $evidenceIds  the drafts' public ids
     * @return int how many were attached
     *
     * @throws CaseEvidenceRefused
     */
    public function handle(User $actor, OrderReturn|OrderDamage $case, array $evidenceIds): int
    {
        $evidenceIds = array_values(array_unique(array_filter($evidenceIds, 'is_string')));

        if ($evidenceIds === []) {
            return 0;
        }

        $drafts = CaseEvidence::query()
            ->whereIn('public_id', $evidenceIds)
            ->where('uploader_id', $actor->id)
            ->where('order_id', $case->order_id)
            ->where('business_account_id', $case->business_account_id)
            ->where('status', CaseEvidence::DRAFT)
            ->lockForUpdate()
            ->get();

        if ($drafts->count() !== count($evidenceIds)) {
            throw CaseEvidenceRefused::notYourDraft();
        }

        foreach ($drafts as $draft) {
            $draft->forceFill([
                $case instanceof OrderReturn ? 'order_return_id' : 'order_damage_id' => $case->id,
                'status' => CaseEvidence::SUBMITTED,
                'submitted_at' => CarbonImmutable::now(),
            ])->save();
        }

        return $drafts->count();
    }
}
