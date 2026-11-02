<?php

namespace App\Http\Resources\Mobile\Supplier\Account;

use App\Domain\Supplier\Models\Supplier;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Supplier landing screen: where the application stands and, once approved,
 * the headline figures.
 *
 * @property array{supplier: Supplier, snapshot: array<string, mixed>|null} $resource
 */
class SupplierDashboardResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Supplier $supplier */
        $supplier = $this->resource['supplier'];
        $snapshot = $this->resource['snapshot'];

        return [
            /** Where the supplier is in the approval funnel. */
            'status' => $supplier->status->value,
            'status_label' => $supplier->status->label(),
            /** Only an approved, un-suspended supplier has a snapshot. */
            'is_operational' => $supplier->isOperational(),
            /** `null` until the supplier is operational. */
            'snapshot' => $snapshot === null ? null : [
                'active_listings' => $snapshot['active_listings'],
                'pending_listings' => $snapshot['pending_listings'],
                'active_offers' => $snapshot['active_offers'],
                'stock_available' => $snapshot['stock_available'],
                'withdrawals_pending' => $snapshot['withdrawals_pending'],
                'payables' => [
                    'pending' => new MoneyResource($snapshot['payables']['pending']),
                    'eligible' => new MoneyResource($snapshot['payables']['eligible']),
                    'settled' => new MoneyResource($snapshot['payables']['settled']),
                ],
                /** `null` when the supplier has no wallet yet. */
                'wallet' => $snapshot['wallet'] === null ? null : [
                    'total' => new MoneyResource($snapshot['wallet']['total']),
                    'available' => new MoneyResource($snapshot['wallet']['available']),
                    'available_for_withdrawal' => new MoneyResource($snapshot['wallet']['available_for_withdrawal']),
                    'reserved' => new MoneyResource($snapshot['wallet']['reserved']),
                ],
            ],
        ];
    }
}