<?php

namespace App\Domain\Kyc\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Kyc\Models\KycBatchGroup;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Models\KycRequirementSlot;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * Copies a batch's live structure into a new, separate batch (D30).
 *
 * The copy is a starting point for a variation ("Identity — long form" from
 * "Identity"): its groups, requirements, slots, selfie setting and applicability
 * are duplicated, each requirement under a fresh key because a requirement can sit
 * in one batch only. The copy begins switched off, unpublished and not the
 * default, so it affects nobody until it has been looked at and activated. Nothing
 * about the original — its versions, rounds or evidence — is touched or shared.
 */
class CloneKycBatch
{
    public function __construct(
        protected GenerateKycKey $keys,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function handle(KycBatch $source, User $by): KycBatch
    {
        return $this->database->transaction(function () use ($source, $by) {
            $name = __('kyc.batch_builder.copy_of', ['name' => $source->name]);

            $copy = KycBatch::create([
                'key' => $this->keys->forBatch($name),
                'audience' => $source->audience->value,
                'name' => $name,
                'name_bn' => $source->name_bn === null ? null : __('kyc.batch_builder.copy_of', ['name' => $source->name_bn]),
                'description' => $source->description,
                'description_bn' => $source->description_bn,
                'selfie_mode' => $source->selfie_mode->value,
                'selfie_allows_upload' => $source->selfie_allows_upload,
                'is_active' => false,
                'is_default' => false,
                'published_version' => 0,
                'sort_order' => (int) KycBatch::query()->max('sort_order') + 1,
            ]);

            foreach ($source->scopes as $scope) {
                $copy->scopes()->create([
                    'package_public_id' => $scope->package_public_id,
                    'country_code' => $scope->country_code,
                ]);
            }

            foreach ($source->groups()->with('items.requirement.slots')->get() as $group) {
                $this->copyGroup($copy, $group);
            }

            $this->audit->handle(new AuditEntry(
                action: 'kyc.batch_cloned',
                actorId: $by->id,
                auditableType: KycBatch::class,
                auditableId: $copy->id,
                after: ['batch' => $copy->key, 'copied_from' => $source->key],
                module: 'kyc',
            ));

            return $copy;
        });
    }

    protected function copyGroup(KycBatch $copy, KycBatchGroup $group): void
    {
        $newGroup = $copy->groups()->create([
            'key' => $group->key,
            'name' => $group->name,
            'name_bn' => $group->name_bn,
            'description' => $group->description,
            'description_bn' => $group->description_bn,
            'completion_rule' => $group->completion_rule,
            'minimum_required' => $group->minimum_required,
            'is_required' => $group->is_required,
            'sort_order' => $group->sort_order,
        ]);

        foreach ($group->items as $item) {
            $type = $item->requirement;

            // A requirement retired from the source is not carried into the copy.
            if ($type === null || $type->isArchived()) {
                continue;
            }

            $newType = KycDocumentType::create([
                'key' => $this->keys->forRequirement($copy, $type->name),
                'kyc_batch_id' => $copy->id,
                'audience' => $copy->audience->value,
                'name' => $type->name,
                'name_bn' => $type->name_bn,
                'instructions' => $type->instructions,
                'instructions_bn' => $type->instructions_bn,
                'is_required' => $type->is_required,
                'is_active' => true,
                'accepted_mime_types' => $type->accepted_mime_types,
                'max_size_kb' => $type->max_size_kb,
                'requires_file' => $type->requires_file,
                'requires_value' => $type->requires_value,
                'value_label' => $type->value_label,
                'sort_order' => $type->sort_order,
            ]);

            foreach ($type->slots()->active()->get() as $slot) {
                KycRequirementSlot::create([
                    ...$slot->only([
                        'key', 'label', 'label_bn', 'instructions', 'instructions_bn', 'is_required', 'evidence_type',
                        'accepted_mime_types', 'max_size_kb', 'value_label', 'value_label_bn', 'is_sensitive', 'sort_order',
                    ]),
                    'kyc_document_type_id' => $newType->id,
                ]);
            }

            $newGroup->items()->create([
                'kyc_document_type_id' => $newType->id,
                'is_required' => $item->is_required,
                'sort_order' => $item->sort_order,
            ]);
        }
    }
}
