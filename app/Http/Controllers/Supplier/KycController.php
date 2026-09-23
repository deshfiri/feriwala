<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Supplier\Actions\OpenSupplierKycRound;
use App\Domain\Supplier\Actions\SubmitSupplierKyc;
use App\Domain\Supplier\Models\Supplier;
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
            'document_types' => SupplierKycDocumentStore::DOCUMENT_TYPES,
        ]);
    }

    public function storeDocument(Request $request, OpenSupplierKycRound $openRound, SupplierKycDocumentStore $store): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $validated = $request->validate([
            'document_type' => ['required', 'string', Rule::in(SupplierKycDocumentStore::DOCUMENT_TYPES)],
            'file' => ['required', 'file', 'max:'.SupplierKycDocumentStore::MAX_SIZE_KB],
        ]);

        $round = $openRound->handle($supplier);

        if (! $round->status->isEditable()) {
            throw ValidationException::withMessages([
                'file' => 'This submission is being reviewed and cannot be changed.',
            ]);
        }

        try {
            $store->store($round, $validated['document_type'], $request->file('file'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return back()->with('success', 'Document uploaded.');
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
