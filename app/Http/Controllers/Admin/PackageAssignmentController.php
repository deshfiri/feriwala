<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Actions\AssignPackage;
use App\Domain\Package\Models\Package;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Assigning a package to an account without a sale (§8.3).
 *
 * Keyed on the account, because the subject of the decision is the business
 * being given something. Reached from the account dossier, which is where an
 * administrator is standing when the question comes up.
 *
 * The dates are asked for rather than derived. §8.3 lists an effective date
 * among the things an administrator configures, and a granted term is where
 * that matters most — a promotion runs for its promotion, not for whatever the
 * package's validity happens to say this month.
 */
class PackageAssignmentController extends Controller
{
    public function __construct(
        protected AssignPackage $assign,
    ) {}

    public function __invoke(Request $request, BusinessAccount $account): RedirectResponse
    {
        $validated = $request->validate([
            'package' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'promotional' => ['boolean'],
        ]);

        $package = Package::query()
            ->where('slug', $validated['package'])
            ->firstOrFail();

        // The package policy, not the account's: this is a decision about what
        // Feriwala gives away, and it is checked before anything is read.
        Gate::authorize('assign', $package);

        try {
            $this->assign->handle(
                account: $account,
                package: $package,
                actor: $this->actor($request),
                reason: $validated['reason'],
                startsAt: CarbonImmutable::parse($validated['starts_at']),
                expiresAt: isset($validated['expires_at'])
                    ? CarbonImmutable::parse($validated['expires_at'])
                    : null,
                promotional: (bool) ($validated['promotional'] ?? false),
            );
        } catch (InvalidArgumentException $exception) {
            // The action's own invariants — a blank reason, a term that ends
            // before it starts — are legitimate answers to a request and
            // belong on the form.
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('package.assign.done')]);

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
}
