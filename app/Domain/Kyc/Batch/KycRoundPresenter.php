<?php

namespace App\Domain\Kyc\Batch;

use App\Domain\Kyc\Enums\KycCompletionRule;

/**
 * A round as its screen needs it: the batch, its groups, each option and slot
 * with what is already on it, and how far along the applicant is (D30).
 *
 * The same payload serves the Partner form and the Supplier form, because both
 * are the same batch engine over different tables. It carries counts, names,
 * sizes and masked values — never a storage path, a checksum, or a typed value
 * in full — and every word is in the reader's language.
 */
final class KycRoundPresenter
{
    public function __construct(
        private readonly KycCompletion $completion,
    ) {}

    /**
     * @return array<string, mixed>|null null for a round that keeps the earlier flow
     */
    public function present(KycRound $round): ?array
    {
        $definition = $round->definition();

        if ($definition === null) {
            return null;
        }

        $evidence = $round->evidence();
        $result = $this->completion->evaluate($round);
        $groups = [];

        foreach ($definition->groups() as $index => $group) {
            $state = $result->groups[$index];
            $rule = KycCompletionRule::from($group['rule']);
            $options = [];

            foreach ($group['requirements'] as $position => $requirement) {
                $option = $state['options'][$position];

                $options[] = [
                    'key' => $requirement['key'],
                    'name' => KycRoundDefinition::localized($requirement, 'name'),
                    'instructions' => KycRoundDefinition::localized($requirement, 'instructions') ?: null,
                    'required' => $requirement['required'],
                    'chosen' => $option['chosen'],
                    'complete' => $option['complete'],
                    'provided' => $option['provided'],
                    'total' => $option['total'],
                    'slots' => array_map(
                        fn (array $slot) => $this->slot($slot, $evidence[KycEvidenceItem::keyFor($requirement['key'], $slot['key'])] ?? null),
                        $requirement['slots'],
                    ),
                ];
            }

            $groups[] = [
                'key' => $group['key'],
                'name' => KycRoundDefinition::localized($group, 'name'),
                'description' => KycRoundDefinition::localized($group, 'description') ?: null,
                'rule' => $rule->value,
                // The instruction in words: "Provide any one", "Provide any 2 of 3".
                'choose' => $rule->choosesBetweenOptions(),
                'minimum' => $state['needed'],
                'required' => $group['required'],
                'needed' => $state['needed'],
                'done' => $state['done'],
                'offered' => $state['offered'],
                'satisfied' => $state['satisfied'],
                'options' => $options,
            ];
        }

        return [
            'name' => $definition->batchName(),
            'description' => $definition->batchDescription(),
            'version' => $definition->version,
            'editable' => $round->isEditable(),
            'groups' => $groups,
            'selfie' => $this->selfie($definition, $result->selfie, $evidence),
            'complete' => $result->isComplete(),
            'missing' => $result->missing(),
        ];
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    private function slot(array $slot, ?KycEvidenceItem $item): array
    {
        return [
            'key' => $slot['key'],
            'label' => KycRoundDefinition::localized($slot, 'label'),
            'instructions' => KycRoundDefinition::localized($slot, 'instructions') ?: null,
            'required' => $slot['required'],
            'evidence' => $slot['evidence'],
            'mimes' => $slot['mimes'],
            'max_kb' => $slot['max_kb'],
            'value_label' => KycRoundDefinition::localized($slot, 'value_label') ?: null,
            'sensitive' => $slot['sensitive'],
            'current' => $item?->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @param  array<string, KycEvidenceItem>  $evidence
     * @return array<string, mixed>|null
     */
    private function selfie(KycRoundDefinition $definition, ?array $state, array $evidence): ?array
    {
        if ($state === null) {
            return null;
        }

        $item = $evidence[KycEvidenceItem::keyFor(KycRoundDefinition::SELFIE_REQUIREMENT, KycRoundDefinition::SELFIE_SLOT)] ?? null;

        return [
            'mode' => $state['mode'],
            'required' => $state['required'],
            'satisfied' => $state['satisfied'],
            'allows_upload' => $definition->selfieAllowsUpload(),
            'requirement' => KycRoundDefinition::SELFIE_REQUIREMENT,
            'slot' => $this->slot($definition->selfieSlot(), $item),
        ];
    }
}
