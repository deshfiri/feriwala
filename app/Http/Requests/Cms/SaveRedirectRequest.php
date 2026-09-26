<?php

namespace App\Http\Requests\Cms;

use App\Domain\Cms\Actions\ManageRedirect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A public-site redirect (§34.1). Site-relative-path, not-self, and unique
 * `from_path` are already enforced by the migration's own CHECK/unique
 * constraints; loop/chain refusal is
 * {@see ManageRedirect}'s own job, checked against
 * the live table rather than expressible here.
 */
class SaveRedirectRequest extends FormRequest
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
        $rules = [
            'to_path' => ['required', 'string', 'max:255', 'starts_with:/'],
            'status_code' => ['required', 'integer', Rule::in([301, 302, 307, 308])],
        ];

        // Editing an existing redirect changes where it goes, never what
        // triggers it — from_path is the row's identity.
        if ($this->route('redirect') === null) {
            $rules['from_path'] = ['required', 'string', 'max:255', 'starts_with:/'];
        }

        return $rules;
    }
}
