<?php

namespace App\Http\Resources\Mobile\Wholesale;

use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Wholesale\Data\CartLineQuote;
use App\Domain\Wholesale\Data\CheckoutQuote;
use App\Http\Resources\Mobile\MoneyResource;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wholesale checkout as the server priced it on this request (§14, P4-6).
 *
 * Every figure here was worked out again just now; nothing a client sent is in
 * it. `fingerprint` names exactly this summary — confirming and placing the order
 * are held against it.
 *
 * @property CheckoutQuote $resource
 */
class CheckoutResource extends JsonResource
{
    /**
     * @param  array{fingerprint: string, payment_methods: list<array<string, mixed>>, confirmation: array{status: string, payment_method: array{name: string, label: string}, confirmed_at: string, total: Money}|null, coupon_code: string|null, address_book: list<array<string, mixed>>, selected_address_id: string|null, pending_order: array{id: string, reference: string}|null, resale_channels: list<string>}  $context
     */
    public function __construct(CheckoutQuote $resource, protected array $context)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;
        $context = $this->context;
        $confirmation = $context['confirmation'];

        return [
            /** Names this exact summary. Send it back to confirm and to place the order. */
            'fingerprint' => $context['fingerprint'],
            /** Gateways that can take this order, and the wallet (with its balance and whether it covers the total). */
            'payment_methods' => $context['payment_methods'],
            /** The confirmation held on the cart, or `null`. `status` is `confirmed`, or `stale` once anything changed. */
            'confirmation' => $confirmation === null ? null : [
                'status' => $confirmation['status'],
                'payment_method' => $confirmation['payment_method'],
                'confirmed_at' => $confirmation['confirmed_at'],
                'total' => new MoneyResource($confirmation['total']),
            ],
            'lines' => array_map(fn (CartLineQuote $line) => $this->line($line), $quote->cart->lines),
            'subtotal' => new MoneyResource($quote->cart->subtotal),
            /** The coupon as entered and what the server made of it, or `null`. */
            'coupon' => $context['coupon_code'] === null ? null : [
                'entered' => $context['coupon_code'],
                'accepted' => $quote->coupon?->isAccepted,
                'code' => $quote->coupon?->coupon?->code,
                'name' => $quote->coupon?->coupon?->name,
                'discount' => MoneyResource::nullable($quote->coupon?->discount),
                'reason' => $quote->coupon?->message(),
            ],
            'discount' => new MoneyResource($quote->discount),
            'delivery' => new MoneyResource($quote->delivery),
            /** Each tax charge, as the server worked it out. */
            'tax' => $quote->tax->toArray(),
            /** Tax added on top of the prices; inclusive tax is already in them. */
            'tax_added' => new MoneyResource($quote->tax->addedTotal()),
            'total' => new MoneyResource($quote->total),
            'addresses' => [
                'billing' => $quote->billingAddress?->toSnapshot(),
                'shipping' => $quote->shippingAddress?->toSnapshot(),
            ],
            /** The account's active saved addresses. */
            'address_book' => $context['address_book'],
            /** The `id` of the saved address this checkout currently uses, or `null`. */
            'selected_address_id' => $context['selected_address_id'],
            'ready_to_confirm' => $quote->isReadyToConfirm(),
            /** An order from this cart already waiting for payment. */
            'pending_order' => $context['pending_order'],
            /** Accepted values of `intended_resale_channel` when placing the order. */
            'resale_channels' => $context['resale_channels'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(CartLineQuote $line): array
    {
        $item = $line->item;
        $variant = $item->variant;

        return [
            'id' => $item->public_id,
            'name' => $item->product->name,
            'sku' => $variant !== null ? $variant->sku : $item->product->sku,
            'variant' => $variant?->values
                ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                ->pluck('value')
                ->implode(' / '),
            'quantity' => $item->quantity,
            'unit_price' => MoneyResource::nullable($line->unitPrice),
            'line_total' => MoneyResource::nullable($line->lineTotal),
        ];
    }
}
