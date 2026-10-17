<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Actions\UploadKycDocumentOnBehalf;
use App\Domain\Kyc\Exceptions\KycIncomplete;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Staff uploading KYC documents for a partner or a Supplier (see
 * {@see UploadKycDocumentOnBehalf}).
 *
 * Two steps, because what may be uploaded depends on the round the applicant
 * would be filling in: the screen first asks what that round offers, then sends
 * the file. Asking opens the round if there is none, which is what the
 * applicant's own KYC screen does on first arrival — and is why it is a request
 * the administrator makes by opening the dialog, not something a page view does.
 */
class KycUploadOnBehalfController extends Controller
{
    public function __construct(
        protected UploadKycDocumentOnBehalf $upload,
    ) {}

    public function partnerTargets(BusinessAccount $account): JsonResponse
    {
        Gate::authorize('uploadKycDocument', $account);

        try {
            return response()->json(['targets' => $this->upload->partnerTargets($account)]);
        } catch (InvalidArgumentException $refused) {
            return response()->json(['targets' => [], 'message' => $refused->getMessage()]);
        }
    }

    public function partnerStore(Request $request, BusinessAccount $account): RedirectResponse
    {
        Gate::authorize('uploadKycDocument', $account);

        $validated = $this->validated($request);

        try {
            $this->upload->forPartner(
                $account,
                $this->actor($request),
                $validated['target'],
                $request->file('file'),
                $validated['reason'],
                (bool) ($validated['submit'] ?? false),
            );
        } catch (InvalidArgumentException|KycIncomplete $refused) {
            throw ValidationException::withMessages(['file' => $refused->getMessage()]);
        }

        return back()->with('success', __('kyc.upload_on_behalf.done'));
    }

    public function supplierTargets(Supplier $supplier): JsonResponse
    {
        Gate::authorize('uploadKycDocument', $supplier);

        try {
            return response()->json(['targets' => $this->upload->supplierTargets($supplier)]);
        } catch (InvalidArgumentException $refused) {
            return response()->json(['targets' => [], 'message' => $refused->getMessage()]);
        }
    }

    public function supplierStore(Request $request, Supplier $supplier): RedirectResponse
    {
        Gate::authorize('uploadKycDocument', $supplier);

        $validated = $this->validated($request);

        try {
            $this->upload->forSupplier(
                $supplier,
                $this->actor($request),
                $validated['target'],
                $request->file('file'),
                $validated['reason'],
                (bool) ($validated['submit'] ?? false),
            );
        } catch (InvalidArgumentException $refused) {
            throw ValidationException::withMessages(['file' => $refused->getMessage()]);
        }

        return back()->with('success', __('kyc.upload_on_behalf.done'));
    }

    /**
     * The size ceiling here is only the outer bound; the round's own limit for
     * the chosen document is what the store enforces.
     *
     * @return array{target: string, reason: string, submit?: bool}
     */
    protected function validated(Request $request): array
    {
        /** @var array{target: string, reason: string, submit?: bool} */
        return $request->validate([
            'target' => ['required', 'string', 'max:200'],
            'file' => ['required', 'file', 'max:10240'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'submit' => ['nullable', 'boolean'],
        ]);
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
