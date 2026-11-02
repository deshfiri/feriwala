<?php

namespace App\Http\Resources\Mobile;

use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Money, as every mobile response carries it: flat Taka, never poisha.
 *
 * Exactly {@see Money::jsonSerialize()} — the server's own rendering — with the
 * shape declared so the OpenAPI document describes it once and every field that
 * holds money references the same schema. The app displays these and never
 * computes with them (non-negotiable 1).
 *
 * @property Money $resource
 */
class MoneyResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{amount: string, currency: string, formatted: string}
     */
    public function toArray(Request $request): array
    {
        return [
            /** Exact decimal Taka as a string, e.g. `"100.50"`. Never a float. */
            'amount' => $this->resource->amount,
            /** ISO currency code. Always `BDT` today. */
            'currency' => $this->resource->currency->value,
            /** Ready to display, e.g. `৳100.50`. */
            'formatted' => $this->resource->format(),
        ];
    }

    /**
     * For a money value that may be absent.
     */
    public static function nullable(?Money $money): ?self
    {
        return $money === null ? null : new self($money);
    }
}
