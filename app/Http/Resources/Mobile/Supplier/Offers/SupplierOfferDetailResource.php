<?php

namespace App\Http\Resources\Mobile\Supplier\Offers;

use App\Domain\Supplier\Models\SupplierOffer;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;

/**
 * An offer in full: the summary and the history of the Supplier's own rate.
 *
 * @mixin SupplierOffer
 */
class SupplierOfferDetailResource extends SupplierOfferResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'rate_history' => $this->priceHistory->map(fn ($change) => [
                'supplier_rate' => new MoneyResource($change->supplier_rate),
                'effective_from' => $change->effective_from->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
