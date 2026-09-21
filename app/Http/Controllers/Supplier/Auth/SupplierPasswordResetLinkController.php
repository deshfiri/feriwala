<?php

namespace App\Http\Controllers\Supplier\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Requesting a Supplier password reset link, against the `suppliers` broker
 * and `supplier_password_reset_tokens` table only (D25, `config/auth.php`).
 */
class SupplierPasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('supplier/auth/forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::broker('suppliers')->sendResetLink(
            $request->only('email'),
        );

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return back()->with('status', __($status));
    }
}
