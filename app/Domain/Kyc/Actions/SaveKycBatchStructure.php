<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Enums\KycCompletionRule;
use App\Domain\Kyc\Enums\KycEvidenceType;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycBatchGroup;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycRequirementSlot;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Saves the live structure of a batch — its groups, their requirements and the
 * evidence slots of each — from what the builder posts (D30).
 *
 * This edits the *live* structure only. Nothing an applicant sees and no round
 * already open changes until a version is published
 * ({@see PublishKycBatchVersion}), so an administrator can work on a batch for as
 * long as they like.
 *
 * The posted structure is the whole truth: anything not in it is taken out. A
 * group is deleted with its places; a requirement taken out of a group is
 * archived rather than deleted (evidence and published versions may refer to it);
 * a slot taken out is archived for the same reason. Items sent with an id are
 * updated and must already belong to this batch — an id from another batch is
 * refused, not adopted. Items without one are created, with a stable key made
 * from the name.
 *
 * Expected shape:
 *
 *   groups[]: id?, name, name_bn?, description?, description_bn?,
 *             rule (all|any|min), minimum?, required,
 *             requirements[]: id?, name, name_bn?, instructions?, instructions_bn?, required,
 *                             slots[]: id?, label, label_bn?, instructions?, instructions_bn?,
 *                                      required, evidence, mimes?, max_kb?, value_label?,
 *                                      value_label_bn?, sensitive
 */
class SaveKycBatchStructure
{
    public function __construct(
        protected GenerateKycKey $keys,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $groups
     *
     * @throws InvalidArgumentException
     */
    public function handle(KycBatch $batch, array $groups, User $by): KycBatch
    {
        if ($batch->isArchived()) {
            throw new InvalidArgumentException(__('kyc.batch_builder.refused.archived'));
        }

        return $this->database->transaction(function () use ($batch, $groups, $by) {
            /** @var KycBatch $locked */
            $locked = KycBatch::query()->lockForUpdate()->findOrFail($batch->id);

            $keptGroups = [];
            $keptTypes = [];

            foreach (array_values($groups) as $position => $input) {
                $group = $this->saveGroup($locked, $input, $position);
                $keptGroups[] = $group->id;
                $groupTypes = [];

                foreach (array_values($input['requirements'] ?? []) as $order => $requirementInput) {
                    $type = $this->saveRequirement($locked, $requirementInput, $order);
                    $keptTypes[] = $type->id;
                    $groupTypes[] = $type->id;

                    $group->items()->updateOrCreate(
                        ['kyc_document_type_id' => $type->id],
                        ['is_required' => (bool) ($requirementInput['required'] ?? true), 'sort_order' => $order],
                    );

                    $this->saveSlots($type, $requirementInput['slots'] ?? []);
                    $this->refreshLegacyColumns($type);
                }

                // Places taken out of this group; the requirement behind one stays
                // in the catalogue, and is retired below if nothing else uses it.
                $group->items()->whereNotIn('kyc_document_type_id', $groupTypes === [] ? [0] : $groupTypes)->delete();
            }

            KycBatchGroup::query()
                ->where('kyc_batch_id', $locked->id)
                ->whereNotIn('id', $keptGroups === [] ? [0] : $keptGroups)
                ->delete();

            // A requirement no group holds any more is retired, not deleted.
            $locked->types()
                ->whereNull('archived_at')
                ->whereNotIn('id', $keptTypes === [] ? [0] : $keptTypes)
                ->update(['archived_at' => now(), 'is_active' => false]);

            // Counts only — never the wording of a requirement.
            $this->audit->handle(new AuditEntry(
                action: 'kyc.batch_structure_saved',
                actorId: $by->id,
                auditableType: KycBatch::class,
                auditableId: $locked->id,
                after: [
                    'batch' => $locked->key,
                    'groups' => count($keptGroups),
                    'requirements' => count($keptTypes),
                ],
                module: 'kyc',
            ));

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function saveGroup(KycBatch $batch, array $input, int $position): KycBatchGroup
    {
        $rule = KycCompletionRule::from($input['rule'] ?? 'all');

        $attributes = [
            'name' => $input['name'],
            'name_bn' => $this->blankToNull($input['name_bn'] ?? null),
            'description' => $this->blankToNull($input['description'] ?? null),
            'description_bn' => $this->blankToNull($input['description_bn'] ?? null),
            'completion_rule' => $rule,
            'minimum_required' => $rule === KycCompletionRule::Minimum ? max(1, (int) ($input['minimum'] ?? 1)) : null,
            'is_required' => (bool) ($input['required'] ?? true),
            'sort_order' => $position,
        ];

        if (filled($input['id'] ?? null)) {
            $group = KycBatchGroup::query()
                ->where('kyc_batch_id', $batch->id)
                ->where('public_id', $input['id'])
                ->first();

            if ($group === null) {
                throw new InvalidArgumentException(__('kyc.batch_builder.refused.foreign_item'));
            }

            $group->fill($attributes)->save();

            return $group;
        }

        return $batch->groups()->create([...$attributes, 'key' => $this->keys->forGroup($batch, (string) $input['name'])]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function saveRequirement(KycBatch $batch, array $input, int $order): KycDocumentType
    {
        $attributes = [
            'name' => $input['name'],
            'name_bn' => $this->blankToNull($input['name_bn'] ?? null),
            'instructions' => $this->blankToNull($input['instructions'] ?? null),
            'instructions_bn' => $this->blankToNull($input['instructions_bn'] ?? null),
            'is_required' => (bool) ($input['required'] ?? true),
            'is_active' => true,
            'archived_at' => null,
            'sort_order' => $order,
        ];

        if (filled($input['id'] ?? null)) {
            $type = KycDocumentType::query()
                ->where('kyc_batch_id', $batch->id)
                ->where('public_id', $input['id'])
                ->first();

            if ($type === null) {
                throw new InvalidArgumentException(__('kyc.batch_builder.refused.foreign_item'));
            }

            $type->fill($attributes)->save();

            return $type;
        }

        return KycDocumentType::create([
            ...$attributes,
            'key' => $this->keys->forRequirement($batch, (string) $input['name']),
            'kyc_batch_id' => $batch->id,
            'audience' => $batch->audience->value,
            // Derived from the slots right after; the table needs a value now.
            'accepted_mime_types' => [],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    protected function saveSlots(KycDocumentType $type, array $slots): void
    {
        $kept = [];

        foreach (array_values($slots) as $position => $input) {
            $evidence = KycEvidenceType::from($input['evidence']);
            $isFile = $evidence->takesFile();

            $attributes = [
                'label' => $input['label'],
                'label_bn' => $this->blankToNull($input['label_bn'] ?? null),
                'instructions' => $this->blankToNull($input['instructions'] ?? null),
                'instructions_bn' => $this->blankToNull($input['instructions_bn'] ?? null),
                'is_required' => (bool) ($input['required'] ?? true),
                'evidence_type' => $evidence,
                // Null means "the evidence type's own default formats".
                'accepted_mime_types' => $isFile && ! empty($input['mimes']) ? array_values($input['mimes']) : null,
                'max_size_kb' => $isFile ? ($input['max_kb'] ?? null) : null,
                'value_label' => $evidence === KycEvidenceType::Value ? $this->blankToNull($input['value_label'] ?? null) : null,
                'value_label_bn' => $evidence === KycEvidenceType::Value ? $this->blankToNull($input['value_label_bn'] ?? null) : null,
                'is_sensitive' => $evidence === KycEvidenceType::Value && (bool) ($input['sensitive'] ?? false),
                'sort_order' => $position,
                'archived_at' => null,
            ];

            $slot = filled($input['id'] ?? null)
                ? KycRequirementSlot::query()->where('kyc_document_type_id', $type->id)->where('public_id', $input['id'])->first()
                : null;

            if (filled($input['id'] ?? null) && $slot === null) {
                throw new InvalidArgumentException(__('kyc.batch_builder.refused.foreign_item'));
            }

            if ($slot === null) {
                $slot = KycRequirementSlot::create([
                    ...$attributes,
                    'kyc_document_type_id' => $type->id,
                    'key' => $this->keys->forSlot($type, (string) $input['label']),
                ]);
            } else {
                $slot->fill($attributes)->save();
            }

            $kept[] = $slot->id;
        }

        KycRequirementSlot::query()
            ->where('kyc_document_type_id', $type->id)
            ->whereNull('archived_at')
            ->whereNotIn('id', $kept === [] ? [0] : $kept)
            ->update(['archived_at' => now()]);
    }

    /**
     * Keeps the catalogue's older columns agreeing with the slots, for the screens
     * and rounds that still read them: whether a file or a typed value is asked,
     * which formats are allowed, how large.
     */
    protected function refreshLegacyColumns(KycDocumentType $type): void
    {
        $slots = $type->slots()->active()->get();
        $files = $slots->filter(fn (KycRequirementSlot $slot) => $slot->evidence_type->takesFile());
        $value = $slots->first(fn (KycRequirementSlot $slot) => $slot->evidence_type === KycEvidenceType::Value);

        $type->forceFill([
            'requires_file' => $files->isNotEmpty(),
            'requires_value' => $value !== null,
            'value_label' => $value?->value_label,
            'accepted_mime_types' => $files
                ->flatMap(fn (KycRequirementSlot $slot) => $slot->accepted_mime_types ?? $slot->evidence_type->defaultMimeTypes())
                ->unique()
                ->values()
                ->all(),
            'max_size_kb' => (int) ($files->max('max_size_kb') ?? 5120),
        ])->save();
    }

    private function blankToNull(mixed $value): ?string
    {
        return filled($value) ? trim((string) $value) : null;
    }
}
