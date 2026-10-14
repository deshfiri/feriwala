<?php

namespace App\Domain\Kyc\Batch;

use App\Domain\Kyc\Enums\KycCompletionRule;

/**
 * Whether a round on a batch may be approved, and what a correction request
 * should say (D30).
 *
 * Approval needs two things at once: every required group is satisfied
 * ({@see KycCompletion}, where rejected evidence counts as missing), and every
 * piece of evidence that satisfies one has been looked at and accepted. A round
 * whose files were never opened is not a round that has been verified, however
 * complete it looks.
 *
 * Evidence an applicant gave beyond what was owed — an optional slot, an option
 * of an "any one" group they did not choose — does not block approval.
 */
final class KycApprovalReadiness
{
    public function __construct(
        private readonly KycCompletion $completion,
    ) {}

    /**
     * What stands between this round and approval; empty when it may be approved.
     * A round with no batch has no rules here, so nothing blocks it.
     *
     * @return array<int, string>
     */
    public function problems(KycRound $round): array
    {
        $definition = $round->definition();

        if ($definition === null) {
            return [];
        }

        $result = $this->completion->evaluate($round);
        $problems = $result->missing();

        // Unreviewed evidence counts only where it is owed.
        $evidence = $round->evidence();
        $selections = $round->selections();

        foreach ($definition->groups() as $group) {
            $rule = KycCompletionRule::from($group['rule']);
            $chosen = $selections[$group['key']] ?? [];

            foreach ($group['requirements'] as $requirement) {
                $counts = $rule === KycCompletionRule::All
                    ? $requirement['required']
                    : in_array($requirement['key'], $chosen, true);

                if (! $counts) {
                    continue;
                }

                foreach ($requirement['slots'] as $slot) {
                    $item = $evidence[KycEvidenceItem::keyFor($requirement['key'], $slot['key'])] ?? null;

                    if ($slot['required'] && $item !== null && ! $item->isAccepted() && ! $item->isRejected()) {
                        $problems[] = __('kyc.review.not_reviewed', [
                            'item' => KycRoundDefinition::localized($group, 'name').' — '
                                .KycRoundDefinition::localized($requirement, 'name').': '
                                .KycRoundDefinition::localized($slot, 'label'),
                        ]);
                    }
                }
            }
        }

        $selfie = $evidence[KycEvidenceItem::keyFor(KycRoundDefinition::SELFIE_REQUIREMENT, KycRoundDefinition::SELFIE_SLOT)] ?? null;

        if ($selfie !== null && ! $selfie->isAccepted() && ! $selfie->isRejected()) {
            $problems[] = __('kyc.review.not_reviewed', ['item' => __('kyc.batch.selfie_label')]);
        }

        return array_values(array_unique($problems));
    }

    /**
     * Every slot currently rejected, as the instruction the applicant will read:
     * "Identity — National ID: Back side: the photo is blurred".
     *
     * @return array<int, string>
     */
    public function corrections(KycRound $round): array
    {
        $definition = $round->definition();

        if ($definition === null) {
            return [];
        }

        $lines = [];

        foreach ($round->evidence() as $item) {
            if (! $item->isRejected()) {
                continue;
            }

            $where = $this->describe($definition, $item);
            $lines[] = $item->note === null || $item->note === '' ? $where : $where.': '.$item->note;
        }

        return $lines;
    }

    private function describe(KycRoundDefinition $definition, KycEvidenceItem $item): string
    {
        if ($item->requirement === KycRoundDefinition::SELFIE_REQUIREMENT) {
            return __('kyc.batch.selfie_label');
        }

        $group = $definition->groupOf($item->requirement);
        $requirement = $definition->requirement($item->requirement);
        $slot = $definition->slot($item->requirement, $item->slot);

        return implode(' — ', array_filter([
            $group === null ? null : KycRoundDefinition::localized($group, 'name'),
            $requirement === null ? null : KycRoundDefinition::localized($requirement, 'name'),
            $slot === null ? null : KycRoundDefinition::localized($slot, 'label'),
        ]));
    }
}
