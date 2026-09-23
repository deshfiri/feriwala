<?php

namespace App\Http\Controllers\Supplier\Auth;

use App\Domain\Account\Exceptions\ResendTooSoon;
use App\Domain\Supplier\Actions\SendSupplierMobileVerificationCode;
use App\Domain\Supplier\Actions\VerifySupplierMobile;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupplierMobileVerificationController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        return Inertia::render('supplier/verification/mobile', [
            'mobile' => $supplier->mobile,
            'mobileVerified' => $supplier->mobile_verified_at !== null,
        ]);
    }

    public function send(Request $request, SendSupplierMobileVerificationCode $action): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        try {
            $action->handle($supplier);
        } catch (ResendTooSoon $exception) {
            throw ValidationException::withMessages([
                'mobile' => $exception->getMessage(),
            ]);
        }

        return back()->with('status', 'mobile-code-sent');
    }

    public function verify(Request $request, VerifySupplierMobile $action): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $request->validate(['code' => ['required', 'string']]);

        if (! $action->handle($supplier, (string) $request->string('code'))) {
            throw ValidationException::withMessages([
                'code' => __('This code is incorrect or has expired.'),
            ]);
        }

        return redirect()->route('supplier.verification.notice')->with('status', 'mobile-verified');
    }
}
