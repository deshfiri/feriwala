<?php

namespace App\Http\Controllers\Erp;

use App\Domain\Kyc\Actions\OpenKycDraft;
use App\Domain\Kyc\Actions\SubmitKyc;
use App\Domain\Kyc\Exceptions\KycIncomplete;
use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Queries\ApplicableRequirements;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The applicant's KYC screens (§7).
 *
 * Uploads land one at a time rather than as one large submit. A KYC form can
 * carry six documents; making someone re-pick all of them because the last one
 * was too large is the fastest way to lose them at the most fragile point of
 * signing up.
 */
class KycController extends Controller
{
    public function create(
        Request $request,
        OpenKycDraft $openDraft,
        ApplicableRequirements $requirements,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $submission = $openDraft->handle($user);

        return Inertia::render('onboarding/kyc', [
            'submission' => [
                'id' => $submission->public_id,
                'round' => $submission->round,
                'status' => $submission->status->value,
                'status_label' => $submission->status->label(),
                'status_tone' => $submission->status->tone(),
                'is_editable' => $submission->status->isEditable(),
                'deadline_at' => $submission->deadline_at?->toIso8601String(),
            ],
            'requirements' => $requirements->forForm($user, $submission),
            'feedback' => $submission->reviews()->first()?->user_visible_feedback,
        ]);
    }

    /**
     * Attach one document or value to the draft.
     */
    public function storeDocument(
        Request $request,
        OpenKycDraft $openDraft,
        KycDocumentStore $store,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'document_type' => ['required', 'string'],
            'file' => ['nullable', 'file', 'max:10240'],
            'value' => ['nullable', 'string', 'max:255'],
        ]);

        $submission = $openDraft->handle($user);

        if (! $submission->status->isEditable()) {
            throw ValidationException::withMessages([
                'file' => 'This submission is being reviewed and cannot be changed.',
            ]);
        }

        $type = KycDocumentType::query()
            ->where('key', $validated['document_type'])
            ->active()
            ->firstOrFail();

        if ($request->hasFile('file')) {
            try {
                $store->store($submission, $type, $request->file('file'));
            } catch (\InvalidArgumentException $e) {
                // The store's message names the accepted formats and the limit,
                // which is what the applicant needs to fix it.
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }
        }

        if (filled($validated['value'] ?? null)) {
            $submission->fields()->updateOrCreate(
                ['kyc_document_type_id' => $type->id],
                ['value' => $validated['value']],
            );
        }

        return back()->with('success', $type->name.' saved.');
    }

    /**
     * Send the completed round for review.
     */
    public function submit(
        Request $request,
        OpenKycDraft $openDraft,
        SubmitKyc $submit,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $submission = $openDraft->handle($user);

        try {
            $submit->handle($submission);
        } catch (KycIncomplete $e) {
            throw ValidationException::withMessages([
                'submission' => $e->getMessage(),
            ]);
        }

        return to_route('onboarding.status')
            ->with('success', 'Your documents are with our team for review.');
    }
}
