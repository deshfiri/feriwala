<?php

namespace App\Http\Requests\Mobile\Supplier\Kyc;

use App\Domain\Supplier\Actions\OpenSupplierKycRound;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\SupplierKycDocumentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One item of a round on the earlier, flat KYC list (not a batch round).
 */
class StoreKycDocumentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Supplier $supplier */
        $supplier = $this->user();
        $round = app(OpenSupplierKycRound::class)->handle($supplier);

        return [
            /** The requirement `key` from `GET /supplier/kyc`. */
            'document_type' => ['required', 'string', Rule::in($round->requirements()->pluck('key')->all())],
            /** The file, as `multipart/form-data`, for a requirement that takes one. */
            'file' => ['nullable', 'file', 'max:'.SupplierKycDocumentStore::MAX_SIZE_KB],
            /** The typed value, for a requirement that takes one. */
            'value' => ['nullable', 'string', 'max:255'],
        ];
    }
}