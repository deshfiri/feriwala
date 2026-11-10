<?php

namespace App\Http\Requests\Mobile\Kyc\Concerns;

/**
 * Lets the app name a KYC requirement by `document_type`, the field it already
 * sends to the flat list's `POST /kyc/documents`, as well as by `requirement`.
 *
 * The two are the same value — the requirement's `key` — so `document_type` is
 * copied onto `requirement` before validation. `requirement` wins when both are
 * sent, and nothing downstream knows there was an alias.
 */
trait AcceptsDocumentType
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('requirement') && $this->filled('document_type')) {
            $this->merge(['requirement' => $this->input('document_type')]);
        }
    }
}
