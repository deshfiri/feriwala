<?php

namespace App\Http\Controllers;

use App\Domain\Account\Actions\ChangeLocale;
use App\Http\Requests\Account\ChangeLocaleRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Reference controller shape.
 *
 * Validate (in the FormRequest), authorize (in the FormRequest or a policy),
 * delegate (to the Action), respond. There is no business logic here on purpose
 * — see app/Domain/README.md.
 */
class LocaleController extends Controller
{
    public function update(
        ChangeLocaleRequest $request,
        ChangeLocale $changeLocale,
    ): RedirectResponse {
        $changeLocale->handle($request->toData());

        return back();
    }
}
