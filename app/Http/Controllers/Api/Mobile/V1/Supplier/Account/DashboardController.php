<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Account;

use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Queries\SupplierDashboardSnapshot;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Resources\Mobile\Supplier\Account\SupplierDashboardResource;
use Illuminate\Http\Request;

/**
 * The Supplier landing screen (D25, P13-1).
 */
class DashboardController extends MobileController
{
    /**
     * Supplier dashboard
     *
     * Where the application stands and, for an approved supplier, the headline
     * figures: listing and offer counts, stock, pending withdrawals, payable
     * totals and wallet balances (money as exact decimal strings). Open to a
     * supplier in any status; one that is not yet operational gets
     * `snapshot: null`.
     */
    public function __invoke(Request $request, SupplierDashboardSnapshot $snapshot): SupplierDashboardResource
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        return new SupplierDashboardResource([
            'supplier' => $supplier,
            'snapshot' => $supplier->isOperational() ? $snapshot->for($supplier) : null,
        ]);
    }
}