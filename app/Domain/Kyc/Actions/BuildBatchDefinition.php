<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Kyc\Enums\KycCompletionRule;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycBatchGroup;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycRequirementSlot;

/**
 * Reads a batch as it stands now and writes it down as a definition: the plain
 * structure a published version freezes (D30).
 *
 * Pure — no writes. It is also what the builder's preview shows, so what an
 * administrator previews is, by construction, what publishing will freeze.
 *
 * A requirement that is switched off or archived is left out, as it always has
 * been. A requirement with no slot rows (made by an older path) gets the slots
 * its own columns imply, exactly as the migration mapped them.
 */
class BuildBatchDefinition
{
    public const SCHEMA = 1;

    /**
     * @return array<string, mixed>
     */
    public function handle(KycBatch $batch): array
    {
        $batch->load(['scopes', 'groups.items.requirement.slots']);

        return [
            'schema' => self::SCHEMA,
            'batch' => [
                'key' => $batch->key,
                'name' => $batch->name,
                'name_bn' => $batch->name_bn,
                'description' => $batch->description,
                'description_bn' => $batch->description_bn,
                'audience' => $batch->audience->value,
                'selfie' => [
                    'mode' => $batch->selfie_mode->value,
                    'allows_upload' => $batch->selfie_allows_upload,
                ],
                // Who it applies to; empty means everyone. Partner rules only —
                // a Supplier has no package or country to match.
                'applicability' => $batch->scopes->map(fn ($scope) => [
                    'package' => $scope->package_public_id,
                    'country' => $scope->country_code,
                ])->values()->all(),
            ],
            'groups' => $batch->groups->map(fn (KycBatchGroup $group) => $this->group($group))->values()->all(),
        ];
    }

    /**
     * What stops this definition from being published: a group nobody could
     * complete, a requirement with nothing to provide, a minimum larger than the
     * options on offer. Every problem found, so they are fixed in one pass.
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, string>
     */
    public function problems(array $definition): array
    {
        $problems = [];
        $groups = $definition['groups'] ?? [];

        if ($groups === [] && ($definition['batch']['selfie']['mode'] ?? 'disabled') === 'disabled') {
            $problems[] = __('kyc.batch_builder.problems.empty');
        }

        // A requirement is addressed by its key, so it can sit in one group only.
        $seen = [];

        foreach ($groups as $group) {
            foreach ($group['requirements'] as $requirement) {
                if (isset($seen[$requirement['key']])) {
                    $problems[] = __('kyc.batch_builder.problems.duplicate_requirement', ['requirement' => $requirement['name']]);
                }

                $seen[$requirement['key']] = true;
            }
        }

        foreach ($groups as $group) {
            $name = $group['name'];
            $count = count($group['requirements']);

            if ($count === 0) {
                $problems[] = __('kyc.batch_builder.problems.group_empty', ['group' => $name]);

                continue;
            }

            $rule = KycCompletionRule::from($group['rule']);

            if ($rule === KycCompletionRule::Minimum && ($group['minimum'] ?? 0) > $count) {
                $problems[] = __('kyc.batch_builder.problems.minimum_too_high', [
                    'group' => $name,
                    'minimum' => $group['minimum'],
                    'count' => $count,
                ]);
            }

            if ($rule === KycCompletionRule::All && $group['required']
                && array_filter($group['requirements'], fn ($requirement) => $requirement['required']) === []) {
                $problems[] = __('kyc.batch_builder.problems.nothing_required', ['group' => $name]);
            }

            foreach ($group['requirements'] as $requirement) {
                if (array_filter($requirement['slots'], fn ($slot) => $slot['required']) === []) {
                    $problems[] = __('kyc.batch_builder.problems.no_required_slot', [
                        'requirement' => $requirement['name'],
                    ]);
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @return array<string, mixed>
     */
    protected function group(KycBatchGroup $group): array
    {
        $requirements = [];

        foreach ($group->items as $item) {
            $type = $item->requirement;

            if ($type !== null && $type->is_active && ! $type->isArchived()) {
                $requirements[] = $this->requirement($type, $item->is_required, $item->sort_order);
            }
        }

        return [
            'key' => $group->key,
            'name' => $group->name,
            'name_bn' => $group->name_bn,
            'description' => $group->description,
            'description_bn' => $group->description_bn,
            // What the applicant's dropdown for this group is called.
            'selector_label' => $group->selector_label,
            'selector_label_bn' => $group->selector_label_bn,
            'rule' => $group->completion_rule->value,
            'minimum' => $group->minimum_required,
            'required' => $group->is_required,
            'sort' => $group->sort_order,
            'requirements' => $requirements,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function requirement(KycDocumentType $type, bool $required, int $sort): array
    {
        $slots = $type->slots()->active()->get();

        return [
            'key' => $type->key,
            'name' => $type->name,
            'name_bn' => $type->name_bn,
            'instructions' => $type->instructions,
            'instructions_bn' => $type->instructions_bn,
            'required' => $required,
            'sort' => $sort,
            'slots' => $slots->isEmpty()
                ? KycRequirementSlot::derivedFor($type)
                : $slots->map(fn (KycRequirementSlot $slot) => [
                    'key' => $slot->key,
                    'label' => $slot->label,
                    'label_bn' => $slot->label_bn,
                    'instructions' => $slot->instructions,
                    'instructions_bn' => $slot->instructions_bn,
                    'required' => $slot->is_required,
                    'evidence' => $slot->evidence_type->value,
                    'mimes' => $slot->accepted_mime_types ?? $slot->evidence_type->defaultMimeTypes(),
                    'max_kb' => $slot->max_size_kb,
                    'value_label' => $slot->value_label,
                    'value_label_bn' => $slot->value_label_bn,
                    'sensitive' => $slot->is_sensitive,
                    'sort' => $slot->sort_order,
                ])->values()->all(),
        ];
    }
}
