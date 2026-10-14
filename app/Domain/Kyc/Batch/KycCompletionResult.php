<?php

namespace App\Domain\Kyc\Batch;

/**
 * The outcome of {@see KycCompletion}: every group's state, the selfie's, and the
 * plain list of what is still owed (D30).
 */
final class KycCompletionResult
{
    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<string, mixed>|null  $selfie
     */
    public function __construct(
        public readonly array $groups,
        public readonly ?array $selfie,
    ) {}

    public function isComplete(): bool
    {
        foreach ($this->groups as $group) {
            if (! $group['satisfied']) {
                return false;
            }
        }

        return $this->selfie === null || $this->selfie['satisfied'];
    }

    /**
     * What the applicant still has to do, in words they can act on — one line
     * per group or slot, never a document's contents.
     *
     * @return array<int, string>
     */
    public function missing(): array
    {
        $lines = [];

        foreach ($this->groups as $group) {
            if ($group['satisfied']) {
                continue;
            }

            if ($group['rule'] === 'all') {
                foreach ($group['options'] as $option) {
                    if (! $option['required'] || $option['complete']) {
                        continue;
                    }

                    foreach (array_merge($option['missing'], $option['rejected']) as $slot) {
                        $lines[] = $group['name'].' — '.$option['name'].': '.$slot;
                    }
                }

                continue;
            }

            $lines[] = __('kyc.batch.choose_n', [
                'group' => $group['name'],
                'needed' => $group['needed'],
                'done' => $group['done'],
            ]);

            // For the option they started: say what that option is still missing.
            foreach ($group['options'] as $option) {
                if ($option['chosen'] && ! $option['complete']) {
                    foreach (array_merge($option['missing'], $option['rejected']) as $slot) {
                        $lines[] = $option['name'].': '.$slot;
                    }
                }
            }
        }

        if ($this->selfie !== null && ! $this->selfie['satisfied']) {
            $lines[] = __('kyc.batch.selfie_required');
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'complete' => $this->isComplete(),
            'groups' => $this->groups,
            'selfie' => $this->selfie,
            'missing' => $this->missing(),
        ];
    }
}
