<?php

namespace App\Http\Resources\Mobile\Wholesale;

use App\Domain\Wholesale\Data\CartQuote;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wholesale cart as the server prices it on this request (§14).
 *
 * @property CartQuote $resource
 */
class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;

        return [
            'lines' => CartLineResource::collection($quote->lines),
            /** Purchasable lines only. */
            'subtotal' => new MoneyResource($quote->subtotal),
            'line_count' => count($quote->lines),
            'has_problems' => $quote->hasProblems(),
            /** A price moved since the person last saw it; `POST /wholesale/cart/prices` accepts the new prices. */
            'has_price_changes' => $quote->hasPriceChanges(),
            'ready_for_checkout' => $quote->isReadyForCheckout(),
        ];
    }
}
