<?php

namespace App\Http\Requests\Kyc;

use App\Domain\Kyc\Enums\KycAudience;
use App\Domain\Kyc\Models\KycBatch;
use App\Domain\Package\Models\Package;
use App\Support\Localization\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An administrator's input for one verification batch and the rules saying who
 * it applies to.
 */
class SaveKycBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $batch = $this->route('batch');

        return [
            'key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/',
                Rule::unique(KycBatch::class, 'key')->ignore($batch instanceof KycBatch ? $batch->id : null),
            ],
            'audience' => ['required', Rule::enum(KycAudience::class)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            'rules' => ['sometimes', 'array', 'max:50'],
            'rules.*.package' => ['nullable', 'string', Rule::exists(Package::class, 'public_id')->whereNull('deleted_at')],
            'rules.*.country' => ['nullable', 'string', 'size:2', Rule::in(app(Countries::class)->codes())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $rules = $this->input('rules');

        if (! is_array($rules)) {
            return;
        }

        $this->merge([
            'rules' => array_map(function ($rule) {
                if (is_array($rule) && filled($rule['country'] ?? null)) {
                    $rule['country'] = mb_strtoupper(trim((string) $rule['country']));
                }

                return $rule;
            }, $rules),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($validator->getData()['rules'] ?? [] as $index => $rule) {
                // A rule naming neither dimension is the global case, which is
                // what having no rules already means.
                if (blank($rule['package'] ?? null) && blank($rule['country'] ?? null)) {
                    $validator->errors()->add("rules.{$index}.package", __('A rule must name a package, a country, or both.'));
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function batchAttributes(): array
    {
        return $this->safe()->except('rules');
    }

    /**
     * @return array<int, array{package?: string|null, country?: string|null}>
     */
    public function ruleRows(): array
    {
        return $this->validated('rules') ?? [];
    }
}
