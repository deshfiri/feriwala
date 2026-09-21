<?php

namespace App\Http\Controllers\Supplier\Auth;

use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierEmailVerificationPromptController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        if ($supplier->hasVerifiedEmail() && $supplier->mobile_verified_at !== null) {
            return redirect()->route('supplier.dashboard');
        }

        return Inertia::render('supplier/verification/notice', [
            'emailVerified' => $supplier->hasVerifiedEmail(),
            'mobileVerified' => $supplier->mobile_verified_at !== null,
        ]);
    }
}
