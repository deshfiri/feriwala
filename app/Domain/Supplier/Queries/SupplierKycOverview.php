<?php

namespace App\Domain\Supplier\Queries;

use App\Domain\Kyc\Batch\KycRoundPresenter;
use App\Domain\Kyc\Batch\SupplierKycRound;
use App\Domain\Supplier\Actions\OpenSupplierKycRound;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycRequirement;
use App\Domain\Supplier\SupplierKycDocumentStore;

/**
 * A Supplier's own KYC round, as the KYC screen reads it (D25, P13-7).
 *
 * Opens the round on demand, exactly as the web screen does, and reads only
 * through the Supplier's own relations — no identifier is taken from outside.
 * Typed values are masked and storage paths never appear.
 */
class SupplierKycOverview
{
    public function __construct(
        protected OpenSupplierKycRound $openRound,
        protected KycRoundPresenter $presenter,
        protected SupplierKycDocumentStore $store,
    ) {}

    /**
     * @return array{
     *     batch: array<string, mixed>|null,
     *     supplier_status: string,
     *     round: array<string, mixed>,
     *     history: list<array<string, mixed>>,
     *     requirements: list<array<string, mixed>>,
     * }
     */
    public function for(Supplier $supplier): array
    {
        $round = $this->openRound->handle($supplier);
        $round->load('documents');
        $batch = $this->presenter->present(new SupplierKycRound($round, $this->store));

        $history = $supplier->kycSubmissions()->with('documents')->get();
        $requirements = $round->requirements()->get();
        $documents = $round->documents->groupBy('document_type');
        $fields = $round->fields()->get()->keyBy('key');

        return [
            'batch' => $batch,
            'supplier_status' => $supplier->status->value,
            'round' => [
                'id' => $round->public_id,
                'status' => $round->status->value,
                'status_label' => $round->status->label(),
                'is_editable' => $round->status->isEditable(),
                'decision_note' => $round->decision_note,
                'submitted_at' => $round->submitted_at?->toIso8601String(),
                'documents' => $round->documents->map(fn ($document) => [
                    'id' => $document->public_id,
                    'type' => $document->document_type,
                    'label' => $requirements->firstWhere('key', $document->document_type)->name
                        ?? $document->document_type,
                    'original_name' => $document->original_name,
                    'size_bytes' => $document->size_bytes,
                    'mime_type' => $document->mime_type,
                ])->all(),
            ],
            'history' => $history->map(fn ($item) => [
                'id' => $item->public_id,
                'submitted_at' => $item->submitted_at?->toIso8601String(),
                'status' => $item->status->value,
                'status_label' => $item->status->label(),
                'reviewed_at' => $item->reviewed_at?->toIso8601String(),
                'decision_note' => $item->decision_note,
            ])->all(),
            'requirements' => $requirements
                ->sortBy(fn (SupplierKycRequirement $requirement) => [$requirement->batch_sort_order, $requirement->sort_order, $requirement->id])
                ->values()
                ->map(fn (SupplierKycRequirement $requirement) => [
                    'batch' => $requirement->batch_key === null ? null : [
                        'key' => $requirement->batch_key,
                        'name' => $requirement->batch_name,
                        'description' => $requirement->batch_description,
                    ],
                    'key' => $requirement->key,
                    'name' => $requirement->name,
                    'instructions' => $requirement->instructions,
                    'is_required' => $requirement->is_required,
                    'requires_file' => $requirement->requires_file,
                    'requires_value' => $requirement->requires_value,
                    'value_label' => $requirement->value_label,
                    'accepted_mime_types' => $requirement->accepted_mime_types,
                    'max_size_kb' => $requirement->max_size_kb,
                    'uploaded' => $documents->has($requirement->key),
                    'value_preview' => $fields->get($requirement->key)?->masked(),
                ])->all(),
        ];
    }
}
