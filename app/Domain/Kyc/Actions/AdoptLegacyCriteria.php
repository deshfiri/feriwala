<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycBatchGroup;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycRequirementSlot;
use Illuminate\Database\DatabaseManager;

/**
 * Gives a batch's older-style criteria their place in the hierarchy (D30).
 *
 * The first KYC screen knows two levels: a batch, and the criteria inside it. A
 * criterion made there has no group, so the builder — which shows
 * Batch → Group → Option → Evidence slot — would open that batch with nothing in
 * it. This puts each such criterion into an "all required" group called
 * Requirements, as an option, with the evidence slots its own columns imply (one
 * file slot if it asks for a file, one value slot if it asks for a typed value):
 * exactly the mapping the migration applied to everything that existed before
 * groups did. Nobody is asked for more or less than before.
 *
 * Idempotent and additive. A criterion already in a group of the batch is left
 * where it is, a group an administrator made is never touched, and nothing is
 * removed. Archived or switched-off criteria are skipped, as they always have been.
 */
class AdoptLegacyCriteria
{
    public const GROUP_KEY = 'requirements';

    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * @return int how many criteria were placed
     */
    public function handle(KycBatch $batch): int
    {
        $orphans = $batch->types()
            ->whereNull('archived_at')
            ->where('is_active', true)
            ->whereDoesntHave('groupPlaces', fn ($query) => $query->whereIn('kyc_batch_group_id', $batch->groups()->select('kyc_batch_groups.id')))
            ->get();

        if ($orphans->isEmpty()) {
            return 0;
        }

        return $this->database->transaction(function () use ($batch, $orphans) {
            $group = $this->group($batch);
            $next = (int) $group->items()->max('sort_order') + 1;

            foreach ($orphans as $type) {
                $group->items()->create([
                    'kyc_document_type_id' => $type->id,
                    'is_required' => $type->is_required,
                    'sort_order' => $next++,
                ]);

                $this->materialiseSlots($type);
            }

            return $orphans->count();
        });
    }

    protected function group(KycBatch $batch): KycBatchGroup
    {
        $existing = $batch->groups()->where('key', self::GROUP_KEY)->first();

        if ($existing !== null) {
            return $existing;
        }

        return $batch->groups()->create([
            'key' => self::GROUP_KEY,
            'name' => 'Requirements',
            'name_bn' => 'প্রয়োজনীয়তা',
            'completion_rule' => 'all',
            'is_required' => true,
            'sort_order' => (int) $batch->groups()->max('sort_order') + 1,
        ]);
    }

    /**
     * Real slot rows for a criterion that has none, so the builder edits them
     * rather than inventing them on every read.
     */
    protected function materialiseSlots(KycDocumentType $type): void
    {
        if ($type->slots()->exists()) {
            return;
        }

        foreach (KycRequirementSlot::derivedFor($type) as $slot) {
            $type->slots()->create([
                'key' => $slot['key'],
                'label' => $slot['label'],
                'label_bn' => $slot['label_bn'],
                'instructions' => $slot['instructions'],
                'instructions_bn' => $slot['instructions_bn'],
                'is_required' => $slot['required'],
                'evidence_type' => $slot['evidence'],
                'accepted_mime_types' => $slot['mimes'] === [] ? null : $slot['mimes'],
                'max_size_kb' => $slot['max_kb'],
                'value_label' => $slot['value_label'],
                'value_label_bn' => $slot['value_label_bn'],
                'is_sensitive' => $slot['sensitive'],
                'sort_order' => $slot['sort'],
            ]);
        }
    }
}
