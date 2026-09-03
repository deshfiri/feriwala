<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Kyc\Actions\ReviewKyc;
use App\Domain\Kyc\Data\KycDecision;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    protected const SORTABLE = ['submitted_at', 'round'];

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
            ->with('user:id,public_id,name,email,mobile,country')
            ->when($request->string('search')->toString(), function ($query, string $search) {
                $query->whereHas('user', fn ($q) => $q
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
                'round' => $submission->round,
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
                    'name' => $submission->user?->name,
                    'email' => $submission->user?->email,
                    'country' => $submission->user?->country,
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

    public function show(Request $request, KycSubmission $submission): Response
    {
        Gate::authorize('view', $submission);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $mayOpenDocuments = $reviewer->can(PermissionCatalogue::name(
            PermissionModule::Kyc,
            PermissionAction::ViewKycDocuments,
        ));

        $submission->load(['user', 'documents.documentType', 'fields.documentType', 'reviews.reviewer']);

        return Inertia::render('admin/kyc/show', [
            'submission' => [
                'id' => $submission->public_id,
                'round' => $submission->round,
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
                'id' => $submission->user?->public_id,
                'name' => $submission->user?->name,
                'email' => $submission->user?->email,
                'mobile' => $submission->user?->mobile,
                'country' => $submission->user?->country,
                'status_label' => $submission->user?->status->label(),
            ],
            'documents' => $submission->documents->map(fn ($document) => [
                'id' => $document->public_id,
                'type' => $document->documentType?->name,
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

        $review->handle($submission, $decision);

        return to_route('admin.kyc.index')->with('success', 'Decision recorded.');
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
