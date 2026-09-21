<?php

namespace App\Http\Controllers\Supplier\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supplier login/logout, entirely on the `supplier` guard (D25).
 *
 * `Auth::guard('supplier')->attempt()` only ever checks the `suppliers`
 * provider (`config/auth.php`) — a Client/Partner `User` row can never
 * satisfy this, and this action never touches the `web` guard's session key.
 */
class AuthenticatedSupplierSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('supplier/auth/login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('supplier')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('supplier.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('supplier')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('supplier.login');
    }
}
