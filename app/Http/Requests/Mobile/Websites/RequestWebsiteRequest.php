<?php

namespace App\Http\Requests\Mobile\Websites;

use Illuminate\Foundation\Http\FormRequest;

class RequestWebsiteRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /**
             * The shop's name.
             *
             * @example Nasrin Fashion House
             */
            'name' => ['required', 'string', 'min:2', 'max:120'],
            /**
             * The address wanted: a plain label Feriwala hosts (`nasrin`), or the partner's own
             * domain or URL, which is kept for staff to arrange.
             *
             * @example nasrin
             */
            'subdomain' => ['required', 'string', 'max:253'],
        ];
    }
}
