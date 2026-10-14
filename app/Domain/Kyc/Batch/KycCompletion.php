<?php

namespace App\Domain\Kyc\Batch;

use App\Domain\Kyc\Enums\KycCompletionRule;

/**
 * Decides whether a round has given what its batch asks for (D30).
 *
 * This is the only place the answer is worked out, and submission refuses on it —
 * the form's progress bars are a courtesy, never the check.
 *
 * Group rules:
 *
 *  - **all** — every requirement marked required is complete.
 *  - **any** — at least one option is complete.
 *  - **min** — at least N options are complete.
 *
 * In "any" and "min" groups only the options the applicant *chose* count, so a
 * half-started alternative cannot satisfy a group by accident and a finished one
 * the applicant then walked away from cannot either.
 *
 * A requirement is complete when every one of its required slots holds evidence
 * that has not been rejected. Evidence a reviewer rejected counts as missing
 * until it is replaced — the same rule that lets a correction round ask only for
 * what failed.
 */
final class KycCompletion
{
    public function evaluate(KycRound $round): KycCompletionResult
    {
        $definition = $round->definition();

        if ($definition === null) {
            return new KycCompletionResult([], null);
        }

        $evidence = $round->evidence();
        $selections = $round->selections();
        $groups = [];

        foreach ($definition->groups() as $group) {
            $groups[] = $this->group($group, $evidence, $selections[$group['key']] ?? []);
        }

        return new KycCompletionResult($groups, $this->selfie($definition, $evidence));
    }

    /**
     * Whether one requirement has every required slot filled with evidence that
     * stands.
     *
     * @param  array<string, mixed>  $requirement
     * @param  array<string, KycEvidenceItem>  $evidence
     * @return array{complete: bool, missing: array<int, string>, rejected: array<int, string>, provided: int, total: int}
     */
    public function requirement(array $requirement, array $evidence): array
    {
        $missing = [];
        $rejected = [];
        $provided = 0;
        $total = 0;

        foreach ($requirement['slots'] as $slot) {
            if (! $slot['required']) {
                continue;
            }

            $total++;
            $item = $evidence[KycEvidenceItem::keyFor($requirement['key'], $slot['key'])] ?? null;
            $label = KycRoundDefinition::localized($slot, 'label');

            if ($item === null || ! $this->holds($slot, $item)) {
                $missing[] = $label;

                continue;
            }

            if ($item->isRejected()) {
                $rejected[] = $label;

                continue;
            }

            $provided++;
        }

        return [
            'complete' => $missing === [] && $rejected === [],
            'missing' => $missing,
            'rejected' => $rejected,
            'provided' => $provided,
            'total' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, KycEvidenceItem>  $evidence
     * @param  array<int, string>  $chosen
     * @return array<string, mixed>
     */
    private function group(array $group, array $evidence, array $chosen): array
    {
        $rule = KycCompletionRule::from($group['rule']);
        $options = [];

        foreach ($group['requirements'] as $requirement) {
            $state = $this->requirement($requirement, $evidence);

            $options[] = [
                'key' => $requirement['key'],
                'name' => KycRoundDefinition::localized($requirement, 'name'),
                'required' => $requirement['required'],
                'chosen' => ! $rule->choosesBetweenOptions() || in_array($requirement['key'], $chosen, true),
                ...$state,
            ];
        }

        if ($rule === KycCompletionRule::All) {
            $owed = array_values(array_filter($options, fn (array $option) => $option['required']));
            $done = count(array_filter($owed, fn (array $option) => $option['complete']));
            $needed = count($owed);
        } else {
            $needed = min($rule->needed($group['minimum']) ?? 1, count($options));
            $done = count(array_filter($options, fn (array $option) => $option['chosen'] && $option['complete']));
        }

        $satisfied = ! $group['required'] || $done >= $needed;

        return [
            'key' => $group['key'],
            'name' => KycRoundDefinition::localized($group, 'name'),
            'description' => KycRoundDefinition::localized($group, 'description'),
            'rule' => $rule->value,
            'minimum' => $group['minimum'],
            'required' => $group['required'],
            'needed' => $needed,
            'done' => min($done, $needed),
            'offered' => count($options),
            'satisfied' => $satisfied,
            'options' => $options,
        ];
    }

    /**
     * @param  array<string, KycEvidenceItem>  $evidence
     * @return array{mode: string, required: bool, provided: bool, rejected: bool, satisfied: bool}|null
     */
    private function selfie(KycRoundDefinition $definition, array $evidence): ?array
    {
        $mode = $definition->selfieMode();

        if (! $mode->isAsked()) {
            return null;
        }

        $item = $evidence[KycEvidenceItem::keyFor(KycRoundDefinition::SELFIE_REQUIREMENT, KycRoundDefinition::SELFIE_SLOT)] ?? null;
        $required = $mode->value === 'required';
        $provided = $item !== null && ! $item->isRejected();

        return [
            'mode' => $mode->value,
            'required' => $required,
            'provided' => $provided,
            'rejected' => $item?->isRejected() ?? false,
            'satisfied' => ! $required || $provided,
        ];
    }

    /**
     * Whether the evidence on a slot is the kind the slot asks for: a declaration
     * only counts when it was ticked.
     *
     * @param  array<string, mixed>  $slot
     */
    private function holds(array $slot, KycEvidenceItem $item): bool
    {
        return $slot['evidence'] === 'declaration' ? $item->kind === KycEvidenceItem::VALUE : true;
    }
}
