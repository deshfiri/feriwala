<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Kyc\Actions\OpenKycDraft;
use App\Domain\Kyc\Actions\SubmitKyc;
use App\Domain\Kyc\Batch\KycRoundPresenter;
use App\Domain\Kyc\Batch\PartnerKycRound;
use App\Domain\Kyc\Exceptions\KycIncomplete;
use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Kyc\Models\KycDocumentType;
use App\Domain\Kyc\Queries\ApplicableRequirements;
use App\Domain\Kyc\Queries\ApplicantKycHistory;
use App\Http\Controllers\Controller;
use App\Support\Localization\InternationalMobile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
    use ResolvesBusinessAccount;

    public function create(
        Request $request,
        OpenKycDraft $openDraft,
        ApplicableRequirements $requirements,
        KycRoundPresenter $presenter,
        KycDocumentStore $store,
    ): Response {
        $account = $this->businessAccountFor($request);

        $submission = $openDraft->handle($account);

        return Inertia::render('onboarding/kyc', [
            // The grouped form, for a round on a batch version (D30); null for
            // one that keeps the earlier flat list below.
            'batch' => $presenter->present(new PartnerKycRound($submission, $store)),
            'submission' => [
                'id' => $submission->public_id,
                'status' => $submission->status->value,
                'status_label' => $submission->status->label(),
                'status_tone' => $submission->status->tone(),
                'is_editable' => $submission->status->isEditable(),
                'deadline_at' => $submission->deadline_at?->toIso8601String(),
                // The country that issued the document (D39), defaulting to
                // where the partner lives.
                'issuing_country' => $submission->issuing_country ?? $account->owner?->country,
            ],
            'issuing_countries' => $this->issuingCountries(),
            'requirements' => $requirements->forForm($account, $submission),
            'feedback' => $submission->reviews()->first()?->user_visible_feedback,
        ]);
    }

    /**
     * The country that issued the identity document (D39 step 4), saved on the
     * draft as soon as it is chosen — the form saves one thing at a time.
     */
    public function issuingCountry(Request $request, OpenKycDraft $openDraft): RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        // An ISO code is the same country whatever its case.
        $request->merge(['issuing_country' => strtoupper(trim((string) $request->input('issuing_country')))]);

        $validated = $request->validate([
            'issuing_country' => ['required', 'string', 'size:2', Rule::in(array_keys(app(InternationalMobile::class)->codes()))],
        ]);

        $submission = $openDraft->handle($account);

        if (! $submission->status->isEditable()) {
            throw ValidationException::withMessages(['issuing_country' => __('kyc.submission.with_team_title')]);
        }

        $submission->forceFill(['issuing_country' => strtoupper($validated['issuing_country'])])->save();

        return back();
    }

    /**
     * Every country, for "which country issued this document?" (D39).
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function issuingCountries(): array
    {
        return array_values(array_map(
            fn (array $option) => ['value' => $option['value'], 'label' => preg_replace('/ \(\+\d+\)$/', '', $option['label']) ?? $option['label']],
            app(InternationalMobile::class)->options(),
        ));
    }

    /**
     * The applicant's own KYC history (§7.3, P1-27).
     *
     * Self-scoped by construction: the account comes from the signed-in
     * person's membership, never from the URL, so there is no identifier to
     * substitute for somebody else's (§31.3).
     */
    public function history(Request $request, ApplicantKycHistory $history): Response
    {
        $account = $this->businessAccountFor($request);

        return Inertia::render('onboarding/kyc-history', [
            'rounds' => $history->forAccount($account),
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
        $account = $this->businessAccountFor($request);

        $validated = $request->validate([
            'document_type' => ['required', 'string'],
            'file' => ['nullable', 'file', 'max:10240'],
            'value' => ['nullable', 'string', 'max:255'],
        ]);

        $submission = $openDraft->handle($account);

        if ($submission->kyc_batch_version_id !== null) {
            // A round on a batch has its own endpoints, which know about groups
            // and slots; this one only understands the flat list.
            throw ValidationException::withMessages(['file' => __('kyc.batch.refused.use_new_form')]);
        }

        if (! $submission->status->isEditable()) {
            throw ValidationException::withMessages([
                'file' => 'This submission is being reviewed and cannot be changed.',
            ]);
        }

        $type = KycDocumentType::query()
            ->where('key', $validated['document_type'])
            ->forAccounts()
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
        $account = $this->businessAccountFor($request);

        $submission = $openDraft->handle($account);

        // Sending a batch round off is a deliberate act: the applicant confirms
        // that what they gave is theirs and complete.
        if ($submission->kyc_batch_version_id !== null) {
            $request->validate(['confirm' => ['accepted']]);
        }

        // The country that issued the document (D39). Any country; when not
        // sent (an older client), the partner's country of residence.
        if ($request->filled('issuing_country')) {
            $request->merge(['issuing_country' => strtoupper(trim((string) $request->input('issuing_country')))]);
        }

        $issuing = $request->validate([
            'issuing_country' => ['nullable', 'string', 'size:2', Rule::in(array_keys(app(InternationalMobile::class)->codes()))],
        ])['issuing_country'] ?? $account->owner?->country;

        if ($submission->status->isEditable() && $issuing !== null) {
            $submission->forceFill(['issuing_country' => strtoupper($issuing)])->save();
        }

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
