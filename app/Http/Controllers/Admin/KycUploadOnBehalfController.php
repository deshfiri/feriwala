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
    /** The outer bound, in kilobytes; the round's own limit for a document is what the store enforces. */
    protected const CEILING_KB = 10240;

    public function __construct(
        protected UploadKycDocumentOnBehalf $upload,
    ) {}

    public function partnerTargets(BusinessAccount $account): JsonResponse
    {
        Gate::authorize('uploadKycDocument', $account);

        try {
            return response()->json(['targets' => $this->upload->partnerTargets($account), 'max_upload_kb' => $this->largestUploadKb()]);
        } catch (InvalidArgumentException $refused) {
            return response()->json(['targets' => [], 'message' => $refused->getMessage()]);
        }
    }

    public function partnerStore(Request $request, BusinessAccount $account): RedirectResponse
    {
        Gate::authorize('uploadKycDocument', $account);

        $validated = $this->validated($request);
        $verify = (bool) ($validated['verify'] ?? false);

        if ($verify) {
            // A decision as well as an upload: the approval permission too.
            Gate::authorize('verifyKycOnTheSpot', $account);
        }

        try {
            $this->upload->forPartner(
                $account,
                $this->actor($request),
                $validated['target'],
                $request->file('file'),
                $validated['reason'],
                (bool) ($validated['submit'] ?? false),
                $verify,
            );
        } catch (InvalidArgumentException|KycIncomplete $refused) {
            throw ValidationException::withMessages(['file' => $refused->getMessage()]);
        }

        return back()->with('success', __($verify ? 'kyc.upload_on_behalf.verified' : 'kyc.upload_on_behalf.done'));
    }

    public function supplierTargets(Supplier $supplier): JsonResponse
    {
        Gate::authorize('uploadKycDocument', $supplier);

        try {
            return response()->json(['targets' => $this->upload->supplierTargets($supplier), 'max_upload_kb' => $this->largestUploadKb()]);
        } catch (InvalidArgumentException $refused) {
            return response()->json(['targets' => [], 'message' => $refused->getMessage()]);
        }
    }

    public function supplierStore(Request $request, Supplier $supplier): RedirectResponse
    {
        Gate::authorize('uploadKycDocument', $supplier);

        $validated = $this->validated($request);
        $verify = (bool) ($validated['verify'] ?? false);

        try {
            $this->upload->forSupplier(
                $supplier,
                $this->actor($request),
                $validated['target'],
                $request->file('file'),
                $validated['reason'],
                (bool) ($validated['submit'] ?? false),
                $verify,
            );
        } catch (InvalidArgumentException $refused) {
            throw ValidationException::withMessages(['file' => $refused->getMessage()]);
        }

        return back()->with('success', __($verify ? 'kyc.upload_on_behalf.verified' : 'kyc.upload_on_behalf.done'));
    }

    /**
     * The size ceiling here is only the outer bound; the round's own limit for
     * the chosen document is what the store enforces.
     *
     * A file larger than PHP is configured to accept never reaches the
     * validator as a file at all — it arrives as a failed upload — so that case
     * gets its own message naming the limit instead of "failed to upload".
     *
     * @return array{target: string, reason: string, submit?: bool, verify?: bool}
     */
    protected function validated(Request $request): array
    {
        /** @var array{target: string, reason: string, submit?: bool, verify?: bool} */
        return $request->validate([
            'target' => ['required', 'string', 'max:200'],
            'file' => ['required', 'file', 'max:'.self::CEILING_KB],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'submit' => ['nullable', 'boolean'],
            'verify' => ['nullable', 'boolean'],
        ], [
            'file.uploaded' => __('kyc.upload_on_behalf.too_large', ['limit' => $this->limitLabel()]),
            'file.max' => __('kyc.upload_on_behalf.too_large', ['limit' => $this->limitLabel()]),
        ]);
    }

    /**
     * The largest file this server will take in one request, in kilobytes: the
     * smallest of PHP's per-file limit, PHP's whole-request limit and the
     * application's own ceiling. What the dialog tells the person up front.
     */
    protected function largestUploadKb(): int
    {
        $perFile = (int) floor(ini_parse_quantity((string) ini_get('upload_max_filesize')) / 1024);
        $perRequest = (int) floor(ini_parse_quantity((string) ini_get('post_max_size')) / 1024);

        return max(0, min(self::CEILING_KB, $perFile > 0 ? $perFile : self::CEILING_KB, $perRequest > 0 ? $perRequest : self::CEILING_KB));
    }

    protected function limitLabel(): string
    {
        $kb = $this->largestUploadKb();

        return $kb >= 1024 ? floor($kb / 1024).' MB' : $kb.' KB';
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
