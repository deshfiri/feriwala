<?php

namespace App\Http\Requests\ContentLibrary;

use App\Domain\ContentLibrary\ContentBlocks;
use App\Domain\ContentLibrary\Policies\ContentLibraryPolicy;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The shape of a library item as the editor posts it. The deeper checks (does
 * each file exist and match its block, is every Product real) live in the
 * action, which is the only way in.
 */
class SaveContentLibraryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && ($this->isMethod('post') ? ContentLibraryPolicy::canPublish($user) : ContentLibraryPolicy::canEdit($user));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'product_ids' => ['required', 'array', 'min:1', 'max:500'],
            'product_ids.*' => ['required', 'string', 'max:40', 'distinct'],
            'blocks' => ['required', 'array', 'min:1', 'max:'.ContentBlocks::MAX_BLOCKS],
            'blocks.*.type' => ['required', Rule::in(ContentBlocks::TYPES)],
            'blocks.*.text' => ['nullable', 'string', 'max:'.ContentBlocks::TEXT_MAX],
            'blocks.*.file_id' => ['nullable', 'string', 'max:40'],
            'blocks.*.url' => ['nullable', 'string', 'max:2000'],
            'blocks.*.label' => ['nullable', 'string', 'max:255'],
            'blocks.*.description' => ['nullable', 'string', 'max:500'],
            'blocks.*.alt' => ['nullable', 'string', 'max:255'],
            'blocks.*.caption' => ['nullable', 'string', 'max:500'],
        ];
    }
}
