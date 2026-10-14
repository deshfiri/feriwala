<?php

namespace App\Concerns;

use App\Domain\Kyc\Batch\KycEvidenceRefused;
use App\Domain\Kyc\Batch\KycRound;
use App\Domain\Kyc\Batch\RecordKycEvidence;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The applicant's evidence endpoints, written once for both audiences (D30).
 *
 * A controller that uses this says only whose round it is. Every rule — what a
 * slot accepts, what may be changed, what refuses — is in {@see RecordKycEvidence};
 * this validates the request's shape, hands over, and turns a refusal into a form
 * error the applicant can act on.
 *
 * The round always comes from the signed-in person, never from the URL, so there
 * is no identifier here to substitute for somebody else's round (§31.3).
 */
trait RecordsKycEvidence
{
    abstract protected function evidenceRound(Request $request): KycRound;

    public function file(Request $request, RecordKycEvidence $evidence): RedirectResponse
    {
        $validated = $request->validate([
            'requirement' => ['required', 'string', 'max:64'],
            'slot' => ['required', 'string', 'max:48'],
            'file' => ['required', 'file', 'max:10240'],
            'captured_live' => ['nullable', 'boolean'],
            'capture_meta' => ['nullable', 'array'],
            'capture_meta.width' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'capture_meta.height' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'capture_meta.facing' => ['nullable', Rule::in(['user', 'environment'])],
        ]);

        $live = $request->boolean('captured_live');

        // Only the frame's size and which camera took it — enough for a reviewer to
        // see it is a still from a camera. No browser or device identifiers, nothing
        // about the face, and no analysis of it.
        $meta = $live
            ? array_filter([
                'width' => $validated['capture_meta']['width'] ?? null,
                'height' => $validated['capture_meta']['height'] ?? null,
                'facing' => $validated['capture_meta']['facing'] ?? null,
            ])
            : null;

        return $this->attempt('file', fn () => $evidence->file(
            $this->evidenceRound($request),
            $validated['requirement'],
            $validated['slot'],
            $request->file('file'),
            $live,
            $meta,
        ));
    }

    public function value(Request $request, RecordKycEvidence $evidence): RedirectResponse
    {
        $validated = $request->validate([
            'requirement' => ['required', 'string', 'max:64'],
            'slot' => ['required', 'string', 'max:48'],
            'value' => ['required', 'string', 'max:255'],
        ]);

        return $this->attempt('value', fn () => $evidence->value(
            $this->evidenceRound($request),
            $validated['requirement'],
            $validated['slot'],
            $validated['value'],
        ));
    }

    public function remove(Request $request, RecordKycEvidence $evidence): RedirectResponse
    {
        $validated = $request->validate([
            'requirement' => ['required', 'string', 'max:64'],
            'slot' => ['required', 'string', 'max:48'],
        ]);

        return $this->attempt('slot', fn () => $evidence->remove(
            $this->evidenceRound($request),
            $validated['requirement'],
            $validated['slot'],
        ));
    }

    public function choose(Request $request, RecordKycEvidence $evidence): RedirectResponse
    {
        $validated = $request->validate([
            'group' => ['required', 'string', 'max:48'],
            'requirement' => ['required', 'string', 'max:64'],
        ]);

        return $this->attempt('option', fn () => $evidence->choose(
            $this->evidenceRound($request),
            $validated['group'],
            $validated['requirement'],
        ));
    }

    public function deselect(Request $request, RecordKycEvidence $evidence): RedirectResponse
    {
        $validated = $request->validate([
            'group' => ['required', 'string', 'max:48'],
            'requirement' => ['required', 'string', 'max:64'],
        ]);

        return $this->attempt('option', fn () => $evidence->deselect(
            $this->evidenceRound($request),
            $validated['group'],
            $validated['requirement'],
        ));
    }

    /**
     * @param  Closure(): void  $change
     */
    private function attempt(string $field, Closure $change): RedirectResponse
    {
        try {
            $change();
        } catch (KycEvidenceRefused $refused) {
            throw ValidationException::withMessages([$field => $refused->getMessage()]);
        }

        return back();
    }
}
