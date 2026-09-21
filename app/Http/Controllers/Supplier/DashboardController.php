<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureSupplierIsOperational;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Supplier portal's landing screen (D25, P13-1).
 *
 * Reachable regardless of application status — a Draft or Suspended Supplier
 * needs to see where their application stands. Operational actions (listing
 * submission, rates, stock) are their own routes, gated separately by
 * {@see EnsureSupplierIsOperational}.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        return Inertia::render('supplier/dashboard', [
            'status' => $supplier->status,
            'isOperational' => $supplier->isOperational(),
        ]);
    }
}
