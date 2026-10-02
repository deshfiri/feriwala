<?php

namespace App\Http\Controllers\Supplier\Auth;

use App\Domain\Account\MobileVerificationRequirement;
use App\Domain\Supplier\Actions\AdvanceSupplierPastVerification;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierEmailVerificationPromptController extends Controller
{
    public function __invoke(
        Request $request,
        AdvanceSupplierPastVerification $advance,
        MobileVerificationRequirement $requirement,
    ): Response|RedirectResponse {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        // Catches a Supplier who was waiting on mobile when the requirement
        // was switched off.
        $advance->handle($supplier);

        if ($advance->isComplete($supplier)) {
            return redirect()->route('supplier.dashboard');
        }

        return Inertia::render('supplier/verification/notice', [
            'emailVerified' => $supplier->hasVerifiedEmail(),
            'mobileVerified' => $supplier->mobile_verified_at !== null,
            'mobileRequired' => $requirement->isRequired(),
        ]);
    }
}
