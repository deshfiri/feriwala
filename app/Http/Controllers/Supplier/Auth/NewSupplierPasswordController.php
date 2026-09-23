<?php

namespace App\Http\Controllers\Supplier\Auth;

use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Completing a Supplier password reset, against the `suppliers` broker only
 * (D25). Never touches `App\Models\User` or the `users` broker.
 */
class NewSupplierPasswordController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('supplier/auth/reset-password', [
            'email' => $request->string('email'),
            'token' => $request->route('token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::default()],
        ]);

        $status = Password::broker('suppliers')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Supplier $supplier, string $password) {
                // Relies on the model's `hashed` cast (Supplier::casts()),
                // matching the convention CreateNewUser already uses for
                // Client/Partner identities.
                $supplier->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($supplier));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return redirect()->route('supplier.login')->with('status', __($status));
    }
}
