<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Account\Queries\ContactVerificationState;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Kyc\Batch\AssignKycBatch;
use App\Domain\Kyc\Batch\KycApprovalReadiness;
use App\Domain\Kyc\Batch\KycReviewRefused;
use App\Domain\Kyc\Batch\KycRoundPresenter;
use App\Domain\Kyc\Batch\RecordKycVerdict;
use App\Domain\Kyc\Batch\SupplierKycRound;
use App\Domain\Kyc\Exceptions\InvalidKycBatch;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Sourcing\Queries\ProductSuppliersSection;
use App\Domain\Supplier\Actions\ApproveSupplierKyc;
use App\Domain\Supplier\Actions\OpenSupplierKycRound;
use App\Domain\Supplier\Actions\ReactivateSupplier;
use App\Domain\Supplier\Actions\RejectSupplierKyc;
use App\Domain\Supplier\Actions\RequestSupplierKycCorrection;
use App\Domain\Supplier\Actions\SuspendSupplier;
use App\Domain\Supplier\Enums\SupplierKycStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierKycDocument;
use App\Domain\Supplier\Models\SupplierKycSubmission;
use App\Domain\Supplier\SupplierKycDocumentStore;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

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

    public function show(
        Request $request,
        Supplier $supplier,
        KycRoundPresenter $presenter,
        KycApprovalReadiness $readiness,
        SupplierKycDocumentStore $store,
    ): Response {
        Gate::authorize('view', $supplier);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $round = $supplier->kycSubmissions()->with(['documents', 'requirements', 'fields'])->first();
        $statusHistory = $supplier->statusHistory()->with('changedBy')->get();

        // A round on a batch version is reviewed group by group; null for one that
        // keeps the flat document list below.
        $batchRound = $round === null ? null : new SupplierKycRound($round, $store);
        $grouped = $batchRound === null ? null : $presenter->present($batchRound);

        return Inertia::render('admin/suppliers/show', [
            'batch' => $grouped === null || $batchRound === null ? null : [
                ...$grouped,
                'review' => [
                    'problems' => $readiness->problems($batchRound),
                    'corrections' => $readiness->corrections($batchRound),
                    'awaits_review' => $batchRound->awaitsReview(),
                    // Whoever reached this page may open a document; the route
                    // authorises and records each opening.
                    'can_open_documents' => true,
                    'can_judge' => $batchRound->awaitsReview()
                        && Gate::allows('review', $round)
                        && Gate::allows('review', KycBatch::class),
                    'can_view_sensitive' => Gate::allows('viewSensitive', KycBatch::class),
                ],
            ],
            // The templates a Supplier round may be opened against, for staff who
            // may set the Supplier up; empty otherwise so the control is absent.
            'kyc_batches' => Gate::allows('assign', KycBatch::class)
                ? KycBatch::query()->forSuppliers()->available()->orderBy('sort_order')->get()
                    ->map(fn (KycBatch $batch) => [
                        'id' => $batch->public_id,
                        'name' => $batch->name,
                        'is_default' => $batch->is_default,
                    ])->all()
                : [],
            'can_assign_batch' => $round !== null
                && Gate::allows('assign', KycBatch::class)
                && in_array($round->status, [SupplierKycStatus::Draft, SupplierKycStatus::CorrectionRequired], true)
                && $round->documents->isEmpty() && $round->fields->isEmpty(),
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
                'can_verify_contact' => Gate::allows('verify', $supplier),
                'can_manage_setup' => Gate::allows('update', $supplier),
                'can_decide' => Gate::allows('decide', Supplier::class),
                'can_suspend' => $supplier->status === SupplierStatus::Approved && Gate::allows('suspend', $supplier),
                'can_reactivate' => $supplier->status === SupplierStatus::Suspended && Gate::allows('reactivate', $supplier),
            ],
            'round' => $round === null ? null : [
                'id' => $round->public_id,
                'status' => $round->status->value,
                'status_label' => $round->status->label(),
                'submitted_at' => $round->submitted_at?->toIso8601String(),
                'awaits_review' => $supplier->status === SupplierStatus::UnderReview,
                'can_request_correction' => $round->status->value === 'under_review' && Gate::allows('review', $round),
                'documents' => $round->documents->map(fn (SupplierKycDocument $document) => [
                    'id' => $document->public_id,
                    'type' => $document->document_type,
                    'label' => $round->requirements->firstWhere('key', $document->document_type)->name
                        ?? $document->document_type,
                    'original_name' => $document->original_name,
                    'size_bytes' => $document->size_bytes,
                    'mime_type' => $document->mime_type,
                ])->all(),
                'fields' => $round->fields->map(fn ($field) => [
                    'label' => $round->requirements->firstWhere('key', $field->key)->name ?? $field->key,
                    'value' => $field->value,
                ])->all(),
                'missing_required' => $round->requirements
                    ->where('is_required', true)
                    ->filter(fn ($requirement) => ($requirement->requires_file && ! $round->documents->contains('document_type', $requirement->key))
                        || ($requirement->requires_value && ! $round->fields->contains('key', $requirement->key)))
                    ->pluck('name')
                    ->values()
                    ->all(),
            ],
            'contact' => app(ContactVerificationState::class)->for($supplier),
            'assigned_products' => app(ProductSuppliersSection::class)->forSupplier($supplier, $reviewer),
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
            // On a round on a batch the rejected slots and the reviewer's notes on
            // them are added, so a covering message is optional there.
            'feedback' => [$round->kyc_batch_version_id === null ? 'required' : 'nullable', 'string', 'max:1000'],
            'internal_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $action->handle($round, $reviewer->id, $validated['feedback'] ?? '', $validated['internal_reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['feedback' => $e->getMessage()]);
        }

        return back()->with('success', 'Correction requested.');
    }

    /**
     * Accept or reject evidence on a Supplier's round on a batch, slot by slot —
     * one or many verdicts in one post, landing whole or not at all.
     */
    public function reviewEvidence(
        Request $request,
        Supplier $supplier,
        RecordKycVerdict $record,
        SupplierKycDocumentStore $store,
    ): RedirectResponse {
        $round = $supplier->kycSubmissions()->firstOrFail();

        Gate::authorize('review', $round);
        Gate::authorize('review', KycBatch::class);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate([
            'verdicts' => ['required', 'array', 'min:1', 'max:50'],
            'verdicts.*.requirement' => ['required', 'string', 'max:64'],
            'verdicts.*.slot' => ['required', 'string', 'max:48'],
            'verdicts.*.status' => ['required', Rule::in([RecordKycVerdict::ACCEPTED, RecordKycVerdict::REJECTED])],
            'verdicts.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $batchRound = new SupplierKycRound($round, $store);

        try {
            DB::transaction(function () use ($validated, $record, $batchRound, $reviewer) {
                foreach ($validated['verdicts'] as $verdict) {
                    $record->handle($batchRound, $reviewer, $verdict['requirement'], $verdict['slot'], $verdict['status'], $verdict['note'] ?? null);
                }
            });
        } catch (KycReviewRefused $refused) {
            throw ValidationException::withMessages(['verdicts' => $refused->getMessage()]);
        }

        return back()->with('success', __('kyc.review.saved'));
    }

    /**
     * One typed value of a Supplier's round on a batch, in full. The page carries it
     * masked; this is the deliberate, recorded way to read it, behind its own
     * permission. The audit entry names the slot, never the value.
     */
    public function revealValue(
        Request $request,
        Supplier $supplier,
        SupplierKycDocumentStore $store,
        RecordAuditLog $audit,
    ): JsonResponse {
        Gate::authorize('view', $supplier);
        Gate::authorize('viewSensitive', KycBatch::class);

        $validated = $request->validate([
            'requirement' => ['required', 'string', 'max:64'],
            'slot' => ['required', 'string', 'max:48'],
        ]);

        $submission = $supplier->kycSubmissions()->firstOrFail();
        $round = new SupplierKycRound($submission, $store);
        $slot = $round->definition()?->slot($validated['requirement'], $validated['slot']);

        abort_unless($slot !== null && in_array($slot['evidence'], ['value', 'declaration'], true), 404);

        $value = $round->revealValue($validated['requirement'], $validated['slot']);

        abort_if($value === null, 404);

        $audit->handle((new AuditEntry(
            action: 'supplier_kyc.value_viewed',
            actorId: $request->user()?->id,
            auditableType: SupplierKycSubmission::class,
            auditableId: $submission->id,
            after: ['requirement' => $validated['requirement'], 'slot' => $validated['slot']],
            module: PermissionModule::SupplierKyc->value,
        ))->withContext($request));

        return response()->json(['value' => $value])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Pin the Supplier's open round to a chosen batch template (D30). Allowed only
     * while the round has nothing in it: once the Supplier has given evidence,
     * moving them to another batch would orphan it.
     */
    public function assignBatch(
        Request $request,
        Supplier $supplier,
        OpenSupplierKycRound $open,
        AssignKycBatch $assign,
    ): RedirectResponse {
        Gate::authorize('assign', KycBatch::class);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'batch' => ['required', 'string', Rule::exists(KycBatch::class, 'public_id')],
        ]);

        $batch = KycBatch::query()->where('public_id', $validated['batch'])->firstOrFail();
        $round = $open->handle($supplier);

        try {
            $assign->forSupplier($round, $batch, $actor);
        } catch (InvalidKycBatch $exception) {
            throw ValidationException::withMessages(['batch' => $exception->problems]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['batch' => $exception->getMessage()]);
        }

        return back()->with('success', __('kyc.batch.assigned'));
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
        } catch (InvalidArgumentException $e) {
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
        } catch (InvalidArgumentException $e) {
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
        } catch (InvalidArgumentException $e) {
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
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'Supplier reactivated.');
    }
}
