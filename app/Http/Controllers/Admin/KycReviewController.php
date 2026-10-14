<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Kyc\Actions\ReviewKyc;
use App\Domain\Kyc\Batch\KycApprovalReadiness;
use App\Domain\Kyc\Batch\KycReviewRefused;
use App\Domain\Kyc\Batch\KycRoundPresenter;
use App\Domain\Kyc\Batch\PartnerKycRound;
use App\Domain\Kyc\Batch\RecordKycVerdict;
use App\Domain\Kyc\Data\KycDecision;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycSubmission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The KYC review queue (§7.3).
 *
 * Listing submissions and opening the documents inside them are separate
 * permissions. A reviewer who can see that Nusrat Jahan is awaiting review does
 * not thereby get to open her passport — that needs
 * `kyc.view_kyc_documents`, which the policy enforces per document (§7.5).
 */
class KycReviewController extends Controller
{
    /**
     * Columns the table may sort by.
     *
     * A whitelist rather than the parameter itself — `?sort=` arrives from the
     * browser and would otherwise be an ORDER BY the caller controls.
     */
    protected const SORTABLE = ['submitted_at'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', KycSubmission::class);

        $status = $request->string('status')->toString();

        $submissions = KycSubmission::query()
            ->whereIn('status', [KycStatus::Submitted, KycStatus::UnderReview])
            ->when(
                in_array($status, [KycStatus::Submitted->value, KycStatus::UnderReview->value], true),
                fn ($query) => $query->where('status', $status),
            )
            ->with('businessAccount.owner:id,public_id,name,email,mobile,country')
            ->when($request->string('search')->toString(), function ($query, string $search) {
                $query->whereHas('businessAccount.owner', fn ($q) => $q
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('mobile', 'ilike', "%{$search}%"));
            })
            ->when(
                in_array($request->string('sort')->toString(), self::SORTABLE, true),
                fn ($query) => $query->orderBy(
                    $request->string('sort')->toString(),
                    $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc',
                ),
                // Oldest first. A review queue worked newest-first leaves the
                // people who have waited longest waiting longer.
                fn ($query) => $query->orderBy('submitted_at'),
            )
            ->paginate(25)
            ->withQueryString()
            ->through(fn (KycSubmission $submission) => [
                'id' => $submission->public_id,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
                // How long this applicant has been waiting. The figure a
                // reviewer actually works from — a date alone makes them do
                // the subtraction on every row.
                'waiting_days' => $submission->submitted_at === null
                    ? null
                    : (int) $submission->submitted_at->startOfDay()->diffInDays(now()->startOfDay()),
                'status' => $submission->status->value,
                'status_label' => $submission->status->label(),
                'status_tone' => $submission->status->tone(),
                'applicant' => [
                    'name' => $submission->businessAccount?->owner?->name,
                    'email' => $submission->businessAccount?->owner?->email,
                    'country' => $submission->businessAccount?->owner?->country,
                ],
            ]);

        return Inertia::render('admin/kyc/index', [
            'submissions' => $submissions,
            'statuses' => [
                ['value' => KycStatus::Submitted->value, 'label' => KycStatus::Submitted->label()],
                ['value' => KycStatus::UnderReview->value, 'label' => KycStatus::UnderReview->label()],
            ],
        ]);
    }

    public function show(
        Request $request,
        KycSubmission $submission,
        KycRoundPresenter $presenter,
        KycApprovalReadiness $readiness,
        KycDocumentStore $store,
    ): Response {
        Gate::authorize('view', $submission);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $mayOpenDocuments = $reviewer->can(PermissionCatalogue::name(
            PermissionModule::Kyc,
            PermissionAction::ViewKycDocuments,
        ));

        $submission->load(['businessAccount.owner', 'documents.documentType', 'fields.documentType', 'reviews.reviewer', 'requirements']);

        // The batch each criterion was asked under, as it was when the submission
        // opened — the snapshot, so a later regrouping changes nothing here.
        $batchOf = $submission->requirements->pluck('batch_name', 'kyc_document_type_id');

        // A round on a batch version is reviewed group by group, slot by slot; one
        // opened before batches were versioned keeps the flat lists below.
        $round = new PartnerKycRound($submission, $store);
        $grouped = $presenter->present($round);

        return Inertia::render('admin/kyc/show', [
            'batch' => $grouped === null ? null : [
                ...$grouped,
                'review' => [
                    // What stands between this round and approval, in words.
                    'problems' => $readiness->problems($round),
                    'corrections' => $readiness->corrections($round),
                    'awaits_review' => $round->awaitsReview(),
                    // Whether a file link is offered at all; the document route
                    // checks again on every request (§7.5).
                    'can_open_documents' => $mayOpenDocuments,
                    'can_judge' => $round->awaitsReview() && $reviewer->can('review', $submission),
                ],
            ],
            'submission' => [
                'id' => $submission->public_id,
                'status' => $submission->status->value,
                'status_label' => $submission->status->label(),
                'status_tone' => $submission->status->tone(),
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
                'awaits_review' => $submission->status->awaitsReview(),
                // Whether the decision form appears at all. The policy still
                // runs on submit — this only avoids offering a control that
                // would be refused, including on the reviewer's own submission.
                'can_review' => $reviewer->can('review', $submission),
            ],
            'applicant' => [
                'id' => $submission->businessAccount?->owner?->public_id,
                /*
                 * The business, so the reviewer can open its full file (P1-79).
                 * Offered only to someone the dossier would actually admit —
                 * a link that leads to a refusal is worse than no link.
                 */
                'account_id' => $reviewer->can('viewDossier', $submission->businessAccount)
                    ? $submission->businessAccount?->public_id
                    : null,
                'name' => $submission->businessAccount?->owner?->name,
                'email' => $submission->businessAccount?->owner?->email,
                'mobile' => $submission->businessAccount?->owner?->mobile,
                'country' => $submission->businessAccount?->owner?->country,
                'status_label' => $submission->businessAccount?->status->label(),
            ],
            'documents' => $submission->documents->map(fn ($document) => [
                'id' => $document->public_id,
                'type' => $document->documentType?->name,
                'batch' => $batchOf->get($document->kyc_document_type_id),
                'original_name' => $document->original_name,
                'size_bytes' => $document->size_bytes,
                'mime_type' => $document->mime_type,
                // Whether a link is rendered at all. The policy still checks on
                // every request — this only avoids showing a dead control.
                'can_open' => $mayOpenDocuments,
            ])->all(),

            // Values are masked in the list. A reviewer needing the full number
            // opens the record, which is itself a recorded access.
            'fields' => $submission->fields->map(fn ($field) => [
                'type' => $field->documentType?->name,
                'batch' => $batchOf->get($field->kyc_document_type_id),
                'value' => $mayOpenDocuments ? $field->value : $field->masked(),
            ])->all(),

            'history' => $submission->reviews->map(fn ($review) => [
                'to_status' => $review->to_status->label(),
                'reviewer' => $review->reviewer?->name,
                'reason' => $review->reason,
                'internal_note' => $review->internal_note,
                'user_visible_feedback' => $review->user_visible_feedback,
                'created_at' => $review->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    public function decide(
        Request $request,
        KycSubmission $submission,
        ReviewKyc $review,
        KycApprovalReadiness $readiness,
        KycDocumentStore $store,
    ): RedirectResponse {
        Gate::authorize('review', $submission);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate([
            'outcome' => ['required', Rule::in(['approve', 'reject', 'resubmit'])],
            'reason' => ['nullable', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'feedback' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var 'approve'|'reject'|'resubmit' $outcome Guaranteed by the Rule::in above. */
        $outcome = $validated['outcome'];

        // Sending a round on a batch back tells the applicant exactly which items
        // to replace: the reviewer's notes on each rejected slot are appended to
        // whatever they wrote, so the message is never vaguer than the review.
        if ($outcome === 'resubmit' && $submission->kyc_batch_version_id !== null) {
            $corrections = $readiness->corrections(new PartnerKycRound($submission, $store));

            $validated['feedback'] = trim(implode("\n", array_filter([$validated['feedback'] ?? '', ...$corrections])));
        }

        // The Data object enforces what each outcome requires; validation here
        // only turns a missing field into a form error rather than an exception.
        $decision = match ($outcome) {
            'approve' => KycDecision::approve(
                $reviewer->id,
                internalNote: $validated['internal_note'] ?? null,
            ),
            'reject' => $this->rejection($validated, $reviewer->id),
            'resubmit' => $this->resubmission($validated, $reviewer->id),
        };

        try {
            $review->handle($submission, $decision);
        } catch (KycReviewRefused $refused) {
            // A round on a batch cannot be approved with a group unmet or an item
            // unreviewed, and cannot be sent back with nothing rejected.
            throw ValidationException::withMessages(['outcome' => $refused->getMessage()]);
        }

        return to_route('admin.kyc.index')->with('success', 'Decision recorded.');
    }

    /**
     * Accept or reject evidence on a round on a batch, slot by slot. One or many
     * verdicts in one post, so "accept everything pending" is a single request that
     * either lands whole or not at all.
     */
    public function reviewEvidence(
        Request $request,
        KycSubmission $submission,
        RecordKycVerdict $record,
        KycDocumentStore $store,
    ): RedirectResponse {
        Gate::authorize('review', $submission);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate([
            'verdicts' => ['required', 'array', 'min:1', 'max:50'],
            'verdicts.*.requirement' => ['required', 'string', 'max:64'],
            'verdicts.*.slot' => ['required', 'string', 'max:48'],
            'verdicts.*.status' => ['required', Rule::in([RecordKycVerdict::ACCEPTED, RecordKycVerdict::REJECTED])],
            'verdicts.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $round = new PartnerKycRound($submission, $store);

        try {
            DB::transaction(function () use ($validated, $record, $round, $reviewer) {
                foreach ($validated['verdicts'] as $verdict) {
                    $record->handle($round, $reviewer, $verdict['requirement'], $verdict['slot'], $verdict['status'], $verdict['note'] ?? null);
                }
            });
        } catch (KycReviewRefused $refused) {
            throw ValidationException::withMessages(['verdicts' => $refused->getMessage()]);
        }

        return back()->with('success', __('kyc.review.saved'));
    }

    /**
     * @param  array<string, string|null>  $validated
     */
    protected function rejection(array $validated, int $reviewerId): KycDecision
    {
        $this->requireFields($validated, ['reason', 'feedback']);

        return KycDecision::reject(
            $reviewerId,
            reason: (string) $validated['reason'],
            userVisibleFeedback: (string) $validated['feedback'],
            internalNote: $validated['internal_note'] ?? null,
        );
    }

    /**
     * @param  array<string, string|null>  $validated
     */
    protected function resubmission(array $validated, int $reviewerId): KycDecision
    {
        $this->requireFields($validated, ['feedback']);

        return KycDecision::requestResubmission(
            $reviewerId,
            userVisibleFeedback: (string) $validated['feedback'],
            reason: $validated['reason'] ?? null,
            internalNote: $validated['internal_note'] ?? null,
        );
    }

    /**
     * @param  array<string, string|null>  $validated
     * @param  array<int, string>  $fields
     */
    protected function requireFields(array $validated, array $fields): void
    {
        $messages = [];

        foreach ($fields as $field) {
            if (blank($validated[$field] ?? null)) {
                $messages[$field] = $field === 'feedback'
                    ? 'Tell the applicant what to fix — without it they will resubmit the same thing.'
                    : 'A reason is required and is recorded against this decision.';
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }
}
