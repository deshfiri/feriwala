<?php

namespace App\Http\Requests\Kyc;

use App\Domain\Kyc\Enums\KycAudience;
use App\Domain\Kyc\Enums\KycSelfieMode;
use App\Domain\Package\Models\Package;
use App\Support\Localization\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The details of a KYC batch template: its names, who it is for, the live selfie
 * setting and the rules saying which Partners it applies to (D30).
 *
 * The key is not asked for. It is made from the name when the batch is created and
 * never changes, because rounds and published versions address the batch by it.
 */
class SaveKycBatchTemplateRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'description_bn' => ['nullable', 'string', 'max:1000'],
            'audience' => ['required', Rule::enum(KycAudience::class)],
            'selfie_mode' => ['required', Rule::enum(KycSelfieMode::class)],
            // An upload fallback only means something when a selfie is asked for.
            'selfie_allows_upload' => ['required', 'boolean'],

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
        $attributes = $this->safe()->except('rules');

        // No selfie means no fallback to store.
        if ($attributes['selfie_mode'] === KycSelfieMode::Disabled->value) {
            $attributes['selfie_allows_upload'] = false;
        }

        return $attributes;
    }

    /**
     * @return array<int, array{package?: string|null, country?: string|null}>
     */
    public function ruleRows(): array
    {
        return $this->validated('rules') ?? [];
    }
}
