<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Actions\UploadKycDocumentOnBehalf;
use App\Domain\Kyc\Exceptions\KycIncomplete;
use App\Domain\Kyc\Exceptions\KycUploadRefused;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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

        $input = $this->validated($request);

        if ($input['verify']) {
            // A decision as well as an upload: the approval permission too.
            Gate::authorize('verifyKycOnTheSpot', $account);
        }

        try {
            $this->upload->forPartnerMany($account, $this->actor($request), $input['files'], $input['reason'], $input['submit'], $input['verify'], $input['values']);
        } catch (InvalidArgumentException|KycIncomplete $refused) {
            throw $this->refusal($input, $refused);
        }

        return back()->with('success', __($input['verify'] ? 'kyc.upload_on_behalf.verified' : 'kyc.upload_on_behalf.done'));
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

        $input = $this->validated($request);

        try {
            $this->upload->forSupplierMany($supplier, $this->actor($request), $input['files'], $input['reason'], $input['submit'], $input['verify'], $input['values']);
        } catch (InvalidArgumentException $refused) {
            throw $this->refusal($input, $refused);
        }

        return back()->with('success', __($input['verify'] ? 'kyc.upload_on_behalf.verified' : 'kyc.upload_on_behalf.done'));
    }

    /**
     * What was sent, as one shape whether the dialog posted a single document
     * (`target` + `file`) or the whole checklist (`files[<target>]`).
     *
     * The size ceiling here is only the outer bound; the round's own limit for
     * each document is what the store enforces.
     *
     * A file larger than PHP is configured to accept never reaches the
     * validator as a file at all — it arrives as a failed upload — so that case
     * gets its own message naming the limit instead of "failed to upload".
     *
     * @return array{files: array<string, UploadedFile>, values: array<string, string>, reason: string, submit: bool, verify: bool, many: bool}
     */
    protected function validated(Request $request): array
    {
        // The checklist form posts `files[<target>]`; a form where every file
        // field was left empty posts none at all, which is still that form.
        $many = ! $request->has('target');
        $tooLarge = __('kyc.upload_on_behalf.too_large', ['limit' => $this->limitLabel()]);

        $rules = [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'submit' => ['nullable', 'boolean'],
            'verify' => ['nullable', 'boolean'],
        ];

        $messages = [
            'file.uploaded' => $tooLarge,
            'file.max' => $tooLarge,
            'files.*.uploaded' => $tooLarge,
            'files.*.max' => $tooLarge,
        ];

        if ($many) {
            $rules += [
                'files' => ['array', 'max:40'],
                'files.*' => ['nullable', 'file', 'max:'.self::CEILING_KB],
                'values' => ['array', 'max:40'],
                'values.*' => ['nullable', 'string', 'max:255'],
            ];
        } else {
            $rules += [
                'target' => ['required', 'string', 'max:200'],
                'file' => ['required', 'file', 'max:'.self::CEILING_KB],
            ];
        }

        $validated = $request->validate($rules, $messages);

        /** @var array<string, UploadedFile> $files */
        $files = $many
            ? array_filter((array) $request->file('files'), fn (mixed $file) => $file instanceof UploadedFile)
            : [(string) $validated['target'] => $request->file('file')];

        // Typed values and declarations of a batch; a blank field is nothing given.
        $values = $many
            ? array_filter(
                array_map(fn (mixed $value) => is_string($value) ? trim($value) : '', (array) ($validated['values'] ?? [])),
                fn (string $value) => $value !== '',
            )
            : [];

        if ($files === [] && $values === []) {
            throw ValidationException::withMessages([
                $many ? 'files' : 'file' => __('kyc.upload_on_behalf.choose_a_file'),
            ]);
        }

        return [
            'files' => $files,
            'values' => $values,
            'reason' => (string) $validated['reason'],
            'submit' => (bool) ($validated['submit'] ?? false),
            'verify' => (bool) ($validated['verify'] ?? false),
            'many' => $many,
        ];
    }

    /**
     * A refusal, put beside the field it is about: one document of the checklist,
     * the checklist as a whole, or the single file field of the older form.
     *
     * @param  array{many: bool}  $input
     */
    protected function refusal(array $input, InvalidArgumentException|KycIncomplete $refused): ValidationException
    {
        if (! $input['many']) {
            return ValidationException::withMessages(['file' => $refused->getMessage()]);
        }

        return ValidationException::withMessages([
            $refused instanceof KycUploadRefused ? 'files.'.$refused->target : 'files' => $refused->getMessage(),
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
