<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Actions\ChangeIdentityAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Locking and unlocking a login (§6, P1-17).
 *
 * The subject is a **person**, not a business, so this is keyed on the user and
 * not on the account they happen to belong to. It is reached from the account
 * dossier because that is where an administrator is standing when the question
 * comes up, but locking an owner does nothing to the business — the two
 * lifecycles stay separate (D23).
 */
class IdentityAccessController extends Controller
{
    public function __construct(
        protected ChangeIdentityAccess $access,
    ) {}

    public function lock(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('lock', $user);

        $this->apply(
            fn (string $reason) => $this->access->lock($user, $this->actor($request), $reason),
            $this->reason($request),
            __('Sign-in locked.'),
        );

        return back();
    }

    public function unlock(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('lock', $user);

        $this->apply(
            fn (string $reason) => $this->access->unlock($user, $this->actor($request), $reason),
            $this->reason($request),
            __('Sign-in restored.'),
        );

        return back();
    }

    /**
     * The administrator making the change. Behind `auth`, so null means the
     * middleware stack changed underneath us rather than a real guest.
     */
    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }

    /**
     * The recorded reason, required in both directions (§32.2).
     */
    protected function reason(Request $request): string
    {
        /** @var array{reason: string} $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        return $validated['reason'];
    }

    /**
     * Run the change, turning the action's own invariants into form errors.
     *
     * The action refuses to let somebody lock themselves whatever their
     * permissions say, and the state machine refuses a move it does not declare
     * — a closed account cannot be locked. Both are legitimate answers to a
     * request, so they belong on the form rather than on an error page.
     *
     * @param  callable(string): void  $change
     */
    protected function apply(callable $change, string $reason, string $message): void
    {
        try {
            $change($reason);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'reason' => $exception->getMessage(),
            ]);
        } catch (IllegalStateTransition) {
            // Said in the reader's terms rather than the state machine's: the
            // exception names classes and enum values, which is the right level
            // of detail for a log and the wrong one for a form.
            throw ValidationException::withMessages([
                'reason' => __('This sign-in cannot be changed while the account is in its current state.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
