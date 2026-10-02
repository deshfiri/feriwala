<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Supplier\Actions\OpenSupplierKycRound;
use App\Domain\Supplier\Actions\SubmitSupplierKyc;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycRequirement;
use App\Domain\Supplier\SupplierKycDocumentStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * A Supplier's own KYC screens (D25, P13-7).
 *
 * Every lookup here is scoped through the authenticated Supplier's own
 * relations — never a model resolved from a route parameter — so there is no
 * identifier in these URLs a Supplier could substitute for another one's
 * round or documents (§31.3-equivalent).
 */
class KycController extends Controller
{
    public function create(Request $request, OpenSupplierKycRound $openRound): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $round = $openRound->handle($supplier);
        $round->load('documents');

        $history = $supplier->kycSubmissions()->with('documents')->get();
        $requirements = $round->requirements()->get();
        $documents = $round->documents->groupBy('document_type');
        $fields = $round->fields()->get()->keyBy('key');

        return Inertia::render('supplier/kyc/index', [
            'supplier_status' => $supplier->status->value,
            'round' => [
                'id' => $round->public_id,
                'round' => $round->round,
                'status' => $round->status->value,
                'status_label' => $round->status->label(),
                'is_editable' => $round->status->isEditable(),
                'decision_note' => $round->decision_note,
                'submitted_at' => $round->submitted_at?->toIso8601String(),
                'documents' => $round->documents->map(fn ($document) => [
                    'id' => $document->public_id,
                    'type' => $document->document_type,
                    'label' => $requirements->firstWhere('key', $document->document_type)?->name
                        ?? $document->document_type,
                    'original_name' => $document->original_name,
                    'size_bytes' => $document->size_bytes,
                    'mime_type' => $document->mime_type,
                ])->all(),
            ],
            'history' => $history->map(fn ($item) => [
                'round' => $item->round,
                'status' => $item->status->value,
                'status_label' => $item->status->label(),
                'reviewed_at' => $item->reviewed_at?->toIso8601String(),
                'decision_note' => $item->decision_note,
            ])->all(),
            'requirements' => $requirements->map(fn (SupplierKycRequirement $requirement) => [
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

                // The stored value is masked: the form never echoes it back in full.
                'value_preview' => $fields->get($requirement->key)?->masked(),
            ])->all(),
        ]);
    }

    public function storeDocument(Request $request, OpenSupplierKycRound $openRound, SupplierKycDocumentStore $store): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $round = $openRound->handle($supplier);

        $validated = $request->validate([
            'document_type' => ['required', 'string', Rule::in($round->requirements()->pluck('key')->all())],
            'file' => ['nullable', 'file', 'max:'.SupplierKycDocumentStore::MAX_SIZE_KB],
            'value' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $round->status->isEditable()) {
            throw ValidationException::withMessages([
                'file' => 'This submission is being reviewed and cannot be changed.',
            ]);
        }

        /** @var SupplierKycRequirement $requirement */
        $requirement = $round->requirements()->where('key', $validated['document_type'])->firstOrFail();

        if (! $request->hasFile('file') && blank($validated['value'] ?? null)) {
            throw ValidationException::withMessages([
                $requirement->requires_file ? 'file' : 'value' => 'Provide a file or a value.',
            ]);
        }

        if ($request->hasFile('file')) {
            if (! $requirement->requires_file) {
                throw ValidationException::withMessages(['file' => 'This item takes a value, not a file.']);
            }

            try {
                $store->store($round, $requirement, $request->file('file'));
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }
        }

        if (filled($validated['value'] ?? null)) {
            if (! $requirement->requires_value) {
                throw ValidationException::withMessages(['value' => 'This item takes a file, not a value.']);
            }

            $round->fields()->updateOrCreate(
                ['key' => $requirement->key],
                ['value' => $validated['value']],
            );
        }

        return back()->with('success', $requirement->name.' saved.');
    }

    public function submit(Request $request, SubmitSupplierKyc $submit): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        try {
            $submit->handle($supplier);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['submission' => $e->getMessage()]);
        }

        return to_route('supplier.dashboard')->with('success', 'Your documents are with our team for review.');
    }
}
