<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Supplier\Actions\ApproveSupplierKyc;
use App\Domain\Supplier\Actions\ReactivateSupplier;
use App\Domain\Supplier\Actions\RejectSupplierKyc;
use App\Domain\Supplier\Actions\RequestSupplierKycCorrection;
use App\Domain\Supplier\Actions\SuspendSupplier;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycDocument;
use App\Domain\Supplier\SupplierKycDocumentStore;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff review of Supplier applications (D25, P13-1, P13-7).
 *
 * One queue rather than two: a KYC decision *is* the Supplier decision in
 * this beta (see {@see ApproveSupplierKyc}), so there is no separate "KYC
 * review" screen distinct from the Supplier's own dossier.
 */
class SupplierController extends Controller
{
    protected const SORTABLE = ['submitted_at', 'created_at'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Supplier::class);

        $status = $request->string('status')->toString();

        $suppliers = Supplier::query()
            ->when(
                SupplierStatus::tryFrom($status) !== null,
                fn ($query) => $query->where('status', $status),
            )
            ->when($request->string('search')->toString(), fn ($query, string $search) => $query
                ->where(fn ($inner) => $inner
                    ->where('business_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('mobile', 'ilike', "%{$search}%")))
            ->when($request->filled('submitted_from'), fn ($query) => $query->where('submitted_at', '>=', $request->date('submitted_from')))
            ->when($request->filled('submitted_to'), fn ($query) => $query->where('submitted_at', '<=', $request->date('submitted_to')))
            ->when(
                in_array($request->string('sort')->toString(), self::SORTABLE, true),
                fn ($query) => $query->orderBy(
                    $request->string('sort')->toString(),
                    $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc',
                ),
                fn ($query) => $query->orderByDesc('submitted_at'),
            )
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Supplier $supplier) => [
                'id' => $supplier->public_id,
                'reference' => $supplier->reference,
                'business_name' => $supplier->business_name,
                'contact_person_name' => $supplier->contact_person_name,
                'email' => $supplier->email,
                'status' => $supplier->status->value,
                'status_label' => $supplier->status->label(),
                'status_tone' => $supplier->status->tone(),
                'submitted_at' => $supplier->submitted_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/suppliers/index', [
            'suppliers' => $suppliers,
            'statuses' => array_map(
                fn (SupplierStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                SupplierStatus::cases(),
            ),
        ]);
    }

    public function show(Request $request, Supplier $supplier): Response
    {
        Gate::authorize('view', $supplier);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $round = $supplier->kycSubmissions()->with('documents')->first();
        $statusHistory = $supplier->statusHistory()->with('changedBy')->get();

        return Inertia::render('admin/suppliers/show', [
            'supplier' => [
                'id' => $supplier->public_id,
                'reference' => $supplier->reference,
                'business_name' => $supplier->business_name,
                'contact_person_name' => $supplier->contact_person_name,
                'business_address' => $supplier->business_address,
                'email' => $supplier->email,
                'mobile' => $supplier->mobile,
                'trade_licence_number' => $supplier->trade_licence_number,
                'tax_identification_number' => $supplier->tax_identification_number,
                'status' => $supplier->status->value,
                'status_label' => $supplier->status->label(),
                'status_tone' => $supplier->status->tone(),
                'submitted_at' => $supplier->submitted_at?->toIso8601String(),
                'can_decide' => Gate::allows('decide', Supplier::class),
                'can_suspend' => $supplier->status === SupplierStatus::Approved && Gate::allows('suspend', $supplier),
                'can_reactivate' => $supplier->status === SupplierStatus::Suspended && Gate::allows('reactivate', $supplier),
            ],
            'round' => $round === null ? null : [
                'id' => $round->public_id,
                'round' => $round->round,
                'status' => $round->status->value,
                'status_label' => $round->status->label(),
                'submitted_at' => $round->submitted_at?->toIso8601String(),
                'awaits_review' => $supplier->status === SupplierStatus::UnderReview,
                'can_request_correction' => $round->status->value === 'under_review' && Gate::allows('review', $round),
                'documents' => $round->documents->map(fn (SupplierKycDocument $document) => [
                    'id' => $document->public_id,
                    'type' => $document->document_type,
                    'original_name' => $document->original_name,
                    'size_bytes' => $document->size_bytes,
                    'mime_type' => $document->mime_type,
                ])->all(),
            ],
            'status_history' => $statusHistory->map(fn ($change) => [
                'previous_status' => $change->previous_status?->label(),
                'new_status' => $change->new_status->label(),
                'changed_by' => $change->changedBy?->name,
                'changed_at' => $change->changed_at->toIso8601String(),
                'reason' => $change->reason,
                'public_note' => $change->public_note,
                'source' => $change->source->value,
            ])->all(),
        ]);
    }

    /**
     * Open a Supplier KYC document (§7.5-equivalent). Recorded as an audit
     * entry rather than a dedicated access-log table — the beta's document
     * volume does not warrant a second table, and the audit trail already
     * carries actor, time, and document identity without ever writing the
     * storage path into it.
     */
    public function showDocument(Request $request, Supplier $supplier, SupplierKycDocument $document, SupplierKycDocumentStore $store, RecordAuditLog $audit): HttpResponse
    {
        Gate::authorize('view', $supplier);

        abort_unless($document->submission()->where('supplier_id', $supplier->id)->exists(), 404);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $audit->handle((new AuditEntry(
            action: 'supplier_kyc.document_viewed',
            auditableType: SupplierKycDocument::class,
            auditableId: $document->id,
            after: ['document_type' => $document->document_type],
            module: PermissionModule::SupplierKyc->value,
        ))->withContext($request));

        $contents = $store->read($document);

        return response($contents, 200, ['Content-Type' => $document->mime_type]);
    }

    public function requestCorrection(Request $request, Supplier $supplier, RequestSupplierKycCorrection $action): RedirectResponse
    {
        $round = $supplier->kycSubmissions()->firstOrFail();

        Gate::authorize('review', $round);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate([
            'feedback' => ['required', 'string', 'max:1000'],
            'internal_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $action->handle($round, $reviewer->id, $validated['feedback'], $validated['internal_reason'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['feedback' => $e->getMessage()]);
        }

        return back()->with('success', 'Correction requested.');
    }

    public function approve(Request $request, Supplier $supplier, ApproveSupplierKyc $action): RedirectResponse
    {
        Gate::authorize('decide', Supplier::class);

        $round = $supplier->kycSubmissions()->firstOrFail();

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        try {
            $action->handle($round, $reviewer->id, $validated['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['note' => $e->getMessage()]);
        }

        return to_route('admin.suppliers.index')->with('success', 'Supplier approved.');
    }

    public function reject(Request $request, Supplier $supplier, RejectSupplierKyc $action): RedirectResponse
    {
        Gate::authorize('decide', Supplier::class);

        $round = $supplier->kycSubmissions()->firstOrFail();

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $action->handle($round, $reviewer->id, $validated['reason'], $validated['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return to_route('admin.suppliers.index')->with('success', 'Supplier rejected.');
    }

    public function suspend(Request $request, Supplier $supplier, SuspendSupplier $action): RedirectResponse
    {
        Gate::authorize('suspend', $supplier);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $action->handle($supplier, $reviewer->id, $validated['reason'], $validated['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'Supplier suspended.');
    }

    public function reactivate(Request $request, Supplier $supplier, ReactivateSupplier $action): RedirectResponse
    {
        Gate::authorize('reactivate', $supplier);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $action->handle($supplier, $reviewer->id, $validated['reason'], $validated['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'Supplier reactivated.');
    }
}
