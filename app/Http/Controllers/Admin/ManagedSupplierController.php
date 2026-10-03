<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Actions\ManagePasswordSetup;
use App\Domain\Account\Actions\VerifyContactManually;
use App\Domain\Supplier\Actions\CreateManagedSupplier;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Staff opening a Supplier account on a Supplier's behalf, and managing its
 * password-setup invitation (the Supplier guard's own broker and table).
 */
class ManagedSupplierController extends Controller
{
    public function create(): Response
    {
        Gate::authorize('create', Supplier::class);

        return Inertia::render('admin/suppliers/create');
    }

    public function store(Request $request, CreateManagedSupplier $create): RedirectResponse
    {
        Gate::authorize('create', Supplier::class);

        $supplier = $create->handle($this->actor($request), $request->only([
            'business_name', 'contact_person_name', 'business_address', 'email', 'mobile',
            'trade_licence_number', 'tax_identification_number', 'reason',
        ]));

        return to_route('admin.suppliers.show', $supplier)
            ->with('success', __('managed_accounts.created'));
    }

    public function sendSetupLink(Request $request, Supplier $supplier, ManagePasswordSetup $setup): RedirectResponse
    {
        Gate::authorize('update', $supplier);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->guard(fn () => $setup->issue($supplier, ManagePasswordSetup::SUPPLIERS, $this->actor($request), $validated['reason']));

        return back()->with('success', __('managed_accounts.link_sent'));
    }

    public function revokeSetupLink(Request $request, Supplier $supplier, ManagePasswordSetup $setup): RedirectResponse
    {
        Gate::authorize('update', $supplier);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->guard(fn () => $setup->revoke($supplier, ManagePasswordSetup::SUPPLIERS, $this->actor($request), $validated['reason']));

        return back()->with('success', __('managed_accounts.link_revoked'));
    }

    public function verifyContact(Request $request, Supplier $supplier, VerifyContactManually $verify): RedirectResponse
    {
        Gate::authorize('verify', $supplier);

        $validated = $request->validate([
            'channel' => ['required', Rule::in([VerifyContactManually::EMAIL, VerifyContactManually::MOBILE])],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->guard(fn () => $verify->handle($this->actor($request), $supplier, $validated['channel'], $validated['reason']));

        return back()->with('success', __('managed_accounts.verified'));
    }

    protected function guard(callable $change): void
    {
        try {
            $change();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
