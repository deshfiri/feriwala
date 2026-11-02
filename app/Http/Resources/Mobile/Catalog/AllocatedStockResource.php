<?php

namespace App\Http\Resources\Mobile\Catalog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One SKU of central stock set aside for this account, summed across active
 * warehouses. Never names a warehouse (contract §5.2).
 *
 * @property array{sku: string, product: string, quantity: int, updated_at: string} $resource
 */
class AllocatedStockResource extends JsonResource
{
    /**
     * @return array{sku: string, product: string, quantity: int, updated_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'sku' => $this->resource['sku'],
            'product' => $this->resource['product'],
            /** Units set aside for this account. */
            'quantity' => $this->resource['quantity'],
            /** ISO 8601 time the allocation last changed. */
            'updated_at' => $this->resource['updated_at'],
        ];
    }
}
