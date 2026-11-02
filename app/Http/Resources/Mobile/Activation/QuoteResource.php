<?php

namespace App\Http\Resources\Mobile\Activation;

use App\Domain\Billing\Data\ActivationQuote;
use App\Domain\Billing\Data\QuoteLine;
use App\Http\Resources\Mobile\MoneyResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a payment will cost, worked out on the server (§9, §36.1).
 *
 * The app displays these figures and never derives one of its own. Every
 * component here is what is stored, invoiced and refunded against.
 *
 * @property ActivationQuote $resource
 */
class QuoteResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     lines: list<array{type: string, label: string, amount: MoneyResource, is_deduction: bool}>,
     *     subtotal: MoneyResource,
     *     total: MoneyResource,
     *     is_payable: bool,
     *     tax: list<array{code: string, label: string, rate_basis_points: int, mode: string, net: array{amount: string, currency: string, formatted: string}, tax: array{amount: string, currency: string, formatted: string}, gross: array{amount: string, currency: string, formatted: string}}>,
     *     tax_included: MoneyResource
     * }
     */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;

        return [
            /** Each component. A deduction (a coupon) is shown positive with `is_deduction` true and is taken off the total. */
            'lines' => array_map(fn (QuoteLine $line) => [
                'type' => $line->type->value,
                'label' => $line->label(),
                'amount' => new MoneyResource($line->amount),
                'is_deduction' => $line->type->isDeduction(),
            ], $quote->lines),
            'subtotal' => new MoneyResource($quote->subtotal()),
            /** What the payer actually pays. */
            'total' => new MoneyResource($quote->total()),
            /** False for a fully discounted quote, which is never sent to a gateway. */
            'is_payable' => $quote->isPayable(),
            /** Per-rate tax detail, as an invoice shows it. */
            'tax' => $quote->taxBreakdown()->toArray(),
            'tax_included' => new MoneyResource($quote->taxBreakdown()->includedTotal()),
        ];
    }
}
