<?php

namespace App\Http\Resources\Mobile\Supplier\Stock;

use App\Domain\Supplier\Enums\StockUpdateStatus;
use App\Domain\Supplier\Models\SupplierOffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One offer's availability, with its recent update submissions (D25, P13-15).
 *
 * @mixin SupplierOffer
 */
class SupplierStockOfferResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** The offer's public identifier (ULID): what `stock-updates` is posted against. */
            'id' => $this->public_id,
            'product_name' => $this->product->name,
            'variant' => $this->variant?->public_id,
            /** The quantity staff last approved. */
            'available_quantity' => $this->stock->quantity ?? 0,
            /** True while a submission awaits staff review. */
            'pending_update' => $this->stockUpdates->firstWhere('status', StockUpdateStatus::Pending) !== null,
            'recent_updates' => $this->stockUpdates->map(
                fn ($update) => (new SupplierStockUpdateResource($update))->resolve(),
            )->values()->all(),
        ];
    }
}
