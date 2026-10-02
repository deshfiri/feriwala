<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use App\Domain\Supplier\SupplierKycDocumentStore;

/**
 * Records what a Supplier's KYC round is being asked for, at the moment it
 * opens (mirrors `App\Domain\Kyc\Actions\CaptureRoundRequirements`).
 *
 * What is asked comes from the administrator's document catalogue: every active
 * type whose audience includes Suppliers. Package and country rules do not
 * apply -- a Supplier has neither -- so a type's own `is_required` is the
 * whole answer.
 *
 * While no Supplier requirement has been configured, the round is opened
 * against the built-in vocabulary instead, every item optional, so a Supplier
 * is never stranded with nothing to upload. The submit rule then falls back to
 * "at least one document", which is what it has always been.
 *
 * Idempotent: a round that already has requirements keeps them.
 */
class CaptureSupplierKycRequirements
{
    /**
     * @return int how many requirements the round now carries
     */
    public function handle(SupplierKycSubmission $submission): int
    {
        if ($submission->requirements()->exists()) {
            return $submission->requirements()->count();
        }

        $types = KycDocumentType::query()->forSuppliers()->active()->get();

        if ($types->isEmpty()) {
            return $this->captureBuiltIn($submission);
        }

        foreach ($types as $type) {
            $submission->requirements()->create([
                'kyc_document_type_id' => $type->id,
                'key' => $type->key,
                'name' => $type->name,
                'instructions' => $type->instructions,
                'is_required' => $type->is_required,
                'requires_file' => $type->requires_file,
                'requires_value' => $type->requires_value,
                'value_label' => $type->value_label,
                'accepted_mime_types' => $type->accepted_mime_types,
                'max_size_kb' => $type->max_size_kb,
                'sort_order' => $type->sort_order,
            ]);
        }

        return $types->count();
    }

    protected function captureBuiltIn(SupplierKycSubmission $submission): int
    {
        foreach (SupplierKycDocumentStore::DOCUMENT_TYPES as $position => $key) {
            $submission->requirements()->create([
                'kyc_document_type_id' => null,
                'key' => $key,
                'name' => trans('supplier.kyc.types.'.$key, [], 'en'),
                'is_required' => false,
                'requires_file' => true,
                'requires_value' => false,
                'accepted_mime_types' => SupplierKycDocumentStore::ACCEPTED_MIME_TYPES,
                'max_size_kb' => SupplierKycDocumentStore::MAX_SIZE_KB,
                'sort_order' => $position,
            ]);
        }

        return count(SupplierKycDocumentStore::DOCUMENT_TYPES);
    }
}
