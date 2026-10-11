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
            // No length or count limits anywhere: only the shape is checked.
            'title' => ['required', 'string'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['required', 'string', 'distinct'],
            'blocks' => ['required', 'array', 'min:1'],
            'blocks.*.type' => ['required', Rule::in(ContentBlocks::TYPES)],
            'blocks.*.text' => ['nullable', 'string'],
            'blocks.*.file_id' => ['nullable', 'string'],
            'blocks.*.url' => ['nullable', 'string'],
            'blocks.*.label' => ['nullable', 'string'],
            'blocks.*.description' => ['nullable', 'string'],
            'blocks.*.alt' => ['nullable', 'string'],
            'blocks.*.caption' => ['nullable', 'string'],
        ];
    }
}
