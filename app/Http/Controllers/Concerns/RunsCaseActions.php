<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Cases\Exceptions\CaseEvidenceRefused;
use App\Domain\Damage\Exceptions\DamageRefused;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * What every case action does around the step it runs (D35).
 *
 * The step itself checks the permission and the stage — this only turns what
 * comes back into the response a screen understands: a refusal becomes a message
 * on the field it concerns, a missing right becomes a 403, and a success becomes
 * a toast and a return to the page the person was on. Nothing here decides
 * whether the step is allowed.
 */
trait RunsCaseActions
{
    /**
     * @param  callable(): mixed  $step
     */
    protected function attempt(callable $step, string $success, string $field = 'case'): RedirectResponse
    {
        try {
            $step();
        } catch (ReturnRefused|DamageRefused|CaseEvidenceRefused $refused) {
            throw ValidationException::withMessages([$field => $this->refusal($refused)]);
        } catch (AuthorizationException) {
            abort(403);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __($success)]);

        return back();
    }

    /**
     * A refusal in the reader's own language, where there is a line for it;
     * otherwise what the refusal itself says.
     */
    protected function refusal(ReturnRefused|DamageRefused|CaseEvidenceRefused $refused): string
    {
        $key = 'cases.errors.'.$refused->errorCode;

        return Lang::has($key) ? (string) __($key, array_map('strval', array_filter($refused->details, 'is_scalar'))) : $refused->getMessage();
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
