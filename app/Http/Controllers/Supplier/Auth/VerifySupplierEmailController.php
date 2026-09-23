<?php

namespace App\Http\Controllers\Supplier\Auth;

use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Confirms a Supplier's email against a signed link (D25).
 *
 * Deliberately not Laravel's {@see EmailVerificationRequest}
 * form request: that resolves `Auth::user()` on the default guard, which is
 * `web`, not `supplier`. Every check here is done explicitly against the
 * `supplier` guard instead.
 */
class VerifySupplierEmailController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        abort_unless(
            hash_equals((string) $request->route('id'), (string) $supplier->getKey()),
            403,
        );

        abort_unless(
            hash_equals((string) $request->route('hash'), sha1($supplier->getEmailForVerification())),
            403,
        );

        if ($supplier->hasVerifiedEmail()) {
            return redirect()->route('supplier.verification.notice');
        }

        if ($supplier->markEmailAsVerified()) {
            event(new Verified($supplier));
        }

        return redirect()->route('supplier.verification.notice')->with('status', 'email-verified');
    }
}
