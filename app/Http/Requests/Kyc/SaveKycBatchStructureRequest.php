<?php

namespace App\Http\Requests\Kyc;

use App\Domain\Kyc\Enums\KycCompletionRule;
use App\Domain\Kyc\Enums\KycEvidenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The groups, requirements and evidence slots of a KYC batch template, as the
 * builder posts them (D30). Validates shape and limits only; whether the result
 * could ever be completed is decided when it is published.
 */
class SaveKycBatchStructureRequest extends FormRequest
{
    /** Formats a slot may be restricted to. */
    public const MIMES = ['image/jpeg', 'image/png', 'application/pdf'];

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
            'groups' => ['present', 'array', 'max:20'],
            'groups.*.id' => ['nullable', 'string', 'max:26'],
            'groups.*.name' => ['required', 'string', 'max:120'],
            'groups.*.name_bn' => ['nullable', 'string', 'max:120'],
            'groups.*.description' => ['nullable', 'string', 'max:500'],
            'groups.*.description_bn' => ['nullable', 'string', 'max:500'],
            'groups.*.rule' => ['required', Rule::enum(KycCompletionRule::class)],
            'groups.*.minimum' => ['nullable', 'integer', 'min:1', 'max:20'],
            'groups.*.required' => ['required', 'boolean'],

            'groups.*.requirements' => ['present', 'array', 'max:20'],
            'groups.*.requirements.*.id' => ['nullable', 'string', 'max:26'],
            'groups.*.requirements.*.name' => ['required', 'string', 'max:120'],
            'groups.*.requirements.*.name_bn' => ['nullable', 'string', 'max:120'],
            'groups.*.requirements.*.instructions' => ['nullable', 'string', 'max:1000'],
            'groups.*.requirements.*.instructions_bn' => ['nullable', 'string', 'max:1000'],
            'groups.*.requirements.*.required' => ['required', 'boolean'],

            'groups.*.requirements.*.slots' => ['present', 'array', 'max:10'],
            'groups.*.requirements.*.slots.*.id' => ['nullable', 'string', 'max:26'],
            'groups.*.requirements.*.slots.*.label' => ['required', 'string', 'max:120'],
            'groups.*.requirements.*.slots.*.label_bn' => ['nullable', 'string', 'max:120'],
            'groups.*.requirements.*.slots.*.instructions' => ['nullable', 'string', 'max:500'],
            'groups.*.requirements.*.slots.*.instructions_bn' => ['nullable', 'string', 'max:500'],
            'groups.*.requirements.*.slots.*.required' => ['required', 'boolean'],
            'groups.*.requirements.*.slots.*.evidence' => ['required', Rule::enum(KycEvidenceType::class)],
            'groups.*.requirements.*.slots.*.mimes' => ['nullable', 'array', 'max:3'],
            'groups.*.requirements.*.slots.*.mimes.*' => ['string', Rule::in(self::MIMES)],
            // Up to 20 MB; the applicant form and the server both enforce it.
            'groups.*.requirements.*.slots.*.max_kb' => ['nullable', 'integer', 'min:50', 'max:20480'],
            'groups.*.requirements.*.slots.*.value_label' => ['nullable', 'string', 'max:120'],
            'groups.*.requirements.*.slots.*.value_label_bn' => ['nullable', 'string', 'max:120'],
            'groups.*.requirements.*.slots.*.sensitive' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($validator->getData()['groups'] ?? [] as $index => $group) {
                if (($group['rule'] ?? null) === 'min' && blank($group['minimum'] ?? null)) {
                    $validator->errors()->add("groups.{$index}.minimum", __('kyc.batch_builder.minimum_needed'));
                }
            }
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function groups(): array
    {
        return $this->validated('groups');
    }
}
