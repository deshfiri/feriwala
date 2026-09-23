<?php

namespace App\Http\Controllers\Supplier\Auth;

use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupplierEmailVerificationNotificationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        if ($supplier->hasVerifiedEmail()) {
            return redirect()->route('supplier.dashboard');
        }

        $supplier->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}
