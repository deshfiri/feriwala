<?php

namespace App\Http\Requests\Kyc;

use App\Domain\Kyc\Models\KycDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveKycDocumentTypeRequest extends FormRequest
{
    /**
     * Authorisation is the controller's, through the policy — one lookup, one
     * place.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = $this->route('documentType');

        return [
            // The stable handle the form posts back and the snapshot records.
            // Immutable in practice: changing it would orphan every uploaded
            // document that named it.
            'key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/',
                Rule::unique(KycDocumentType::class, 'key')
                    ->ignore($type instanceof KycDocumentType ? $type->id : null),
            ],
            'name' => ['required', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:2000'],

            'is_required' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],

            'requires_file' => ['required', 'boolean'],
            'requires_value' => ['required', 'boolean'],
            'value_label' => ['nullable', 'string', 'max:120'],

            'accepted_mime_types' => ['required', 'array', 'min:1'],
            'accepted_mime_types.*' => ['required', 'string', 'max:100'],

            // A cap in kilobytes. Zero would accept nothing at all while
            // looking configured, so the floor is one.
            'max_size_kb' => ['required', 'integer', 'min:1', 'max:51200'],

            'sort_order' => ['nullable', 'integer', 'min:0'],

            // §7.2 scoping: global, package, country, or both.
            'scopes' => ['sometimes', 'array'],
            'scopes.*.package' => ['nullable', 'string', 'max:255'],
            'scopes.*.country' => ['nullable', 'string', 'size:2'],
            'scopes.*.is_required' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $data = $validator->getData();

            // A type that asks for neither a file nor a value asks for nothing,
            // and would appear on the form as an item nobody can satisfy.
            if (empty($data['requires_file']) && empty($data['requires_value'])) {
                $validator->errors()->add(
                    'requires_file',
                    __('A document type must ask for a file, a value, or both.'),
                );
            }

            foreach ($data['scopes'] ?? [] as $index => $scope) {
                // A scope naming neither dimension is not a rule, it is the
                // global case — which is what having no scopes already means.
                if (blank($scope['package'] ?? null) && blank($scope['country'] ?? null)) {
                    $validator->errors()->add(
                        "scopes.{$index}.package",
                        __('A rule must name a package, a country, or both.'),
                    );
                }
            }
        });
    }

    /**
     * The document type's own fields.
     *
     * Deliberately not called `attributes()`: FormRequest already defines that
     * for validation display names, and overriding it would quietly break every
     * error message this form produces.
     *
     * @return array<string, mixed>
     */
    public function typeAttributes(): array
    {
        return $this->safe()->except('scopes');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function scopeRules(): array
    {
        /** @var array<int, array<string, mixed>> $scopes */
        $scopes = $this->validated('scopes') ?? [];

        return $scopes;
    }
}
