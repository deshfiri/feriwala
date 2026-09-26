<?php

namespace App\Http\Requests\Cms;

use App\Domain\Cms\Rules\SafeMenuUrl;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/**
 * A menu item points at exactly one destination — an internal route name or
 * a safe external URL, never both and never neither, mirroring the
 * migration's own CHECK constraint (§34).
 */
class SaveMenuItemRequest extends FormRequest
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
            'label_en' => ['required', 'string', 'max:255'],
            'label_bn' => ['nullable', 'string', 'max:255'],
            'route_name' => [
                'required_without:external_url', 'prohibits:external_url',
                'nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                    if (! Route::has((string) $value)) {
                        $fail('The selected route does not exist.');
                    }
                },
            ],
            'external_url' => [
                'required_without:route_name', 'prohibits:route_name',
                'nullable', 'string', 'max:255', new SafeMenuUrl,
            ],
            'is_enabled' => ['boolean'],
            'link_target' => ['required', Rule::in(['self', 'blank'])],
            'parent_id' => ['nullable', 'string'],
        ];
    }
}
