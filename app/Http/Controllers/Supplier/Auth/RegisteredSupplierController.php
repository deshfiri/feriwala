<?php

namespace App\Http\Controllers\Supplier\Auth;

use App\Domain\Supplier\Actions\RegisterSupplier;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supplier registration (D25, P13-1).
 *
 * Hand-rolled against the `supplier` guard rather than reusing Fortify, which
 * is bound to `web` (`config/fortify.php`). A Supplier is never a Client/
 * Partner `User` row — this creates only a {@see Supplier}.
 */
class RegisteredSupplierController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('supplier/auth/register');
    }

    public function store(Request $request, RegisterSupplier $register): RedirectResponse
    {
        $supplier = $register->handle($request->all());

        Auth::guard('supplier')->login($supplier);

        // Triggers the queued listener that sends email verification for any
        // MustVerifyEmail model, guard-agnostic (see EventServiceProvider).
        event(new Registered($supplier));

        $request->session()->regenerate();

        return redirect()->route('supplier.verification.notice');
    }
}
