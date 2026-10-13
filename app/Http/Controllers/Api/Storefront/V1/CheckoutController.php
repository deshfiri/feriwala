<?php

namespace App\Http\Controllers\Api\Storefront\V1;

use App\Domain\Inventory\Queries\StockAvailability;
use App\Domain\Location\Queries\DeliveryAreas;
use App\Domain\Order\Data\WebsiteOrderLine;
use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Order\Queries\PriceWebsiteOrder;
use App\Domain\Order\Queries\WebsiteOrderReservability;
use App\Domain\Order\WebsiteCheckoutRules;
use App\Domain\Website\Api\StorefrontError;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * What a cart would cost, worked out by the ERP before the customer commits
 * (D30, P2 H1–H3, contract §6.0).
 *
 * **Advisory, and nothing more.** The figures come from the same pricing
 * ({@see PriceWebsiteOrder}) and the same checkout rules
 * ({@see WebsiteCheckoutRules}) order placement uses, so a cart built from a
 * quote is accepted as it stands — but `POST /orders` prices it again and
 * refuses any difference with `price_mismatch`, exactly as before. A quote
 * guarantees nothing, holds no stock, and writes nothing: no order, payment,
 * reservation, charge or ledger entry (H1, H2).
 *
 * The website is the credential's; nothing here reads a website or account from
 * the request.
 */
class CheckoutController extends StorefrontController
{
    /** How long a quote's figures are presented as current. A hint only. */
    public const VALID_FOR_MINUTES = 10;

    public function __construct(
        protected PriceWebsiteOrder $pricing,
        protected WebsiteCheckoutRules $rules,
        protected WebsiteOrderReservability $reservability,
        protected StockAvailability $stock,
        protected DeliveryAreas $areas,
    ) {}

    public function quote(Request $request): JsonResponse
    {
        $website = $this->website($request);

        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.sku' => ['required', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'payment_method' => ['required', 'string', 'max:20'],
            'delivery' => ['sometimes', 'array'],
            'delivery.area_id' => ['nullable', 'string', 'max:80'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            return $this->noStore(StorefrontError::respond($request, 422, 'validation_failed', 'The quote request is not valid.', [
                'fields' => $validator->errors()->toArray(),
            ]));
        }

        /** @var array{items: list<array{sku: string, quantity: int|string}>, payment_method: string, delivery?: array{area_id?: string|null}, coupon_code?: string|null} $input */
        $input = $validator->validated();

        $items = array_map(fn (array $item) => [
            'sku' => mb_strtoupper(trim($item['sku'])),
            'quantity' => (int) $item['quantity'],
        ], $input['items']);

        if (count(array_unique(array_column($items, 'sku'))) !== count($items)) {
            return $this->noStore(StorefrontError::respond($request, 422, 'validation_failed', 'Each BPC may appear once.', [
                'field' => 'items',
            ]));
        }

        $areaId = $input['delivery']['area_id'] ?? null;

        if ($areaId !== null && $this->areas->find($areaId) === null) {
            return $this->noStore(StorefrontError::respond($request, 422, 'validation_failed', 'No such delivery area.', [
                'field' => 'delivery.area_id',
            ]));
        }

        try {
            $this->rules->assertAcceptingOrders($website);
            $this->rules->assertPaymentRequest($website, $input['payment_method']);
            $this->rules->assertNoCoupon($input['coupon_code'] ?? null);

            $quote = $this->pricing->quote($website, $items);
        } catch (WebsiteOrderRefused $refused) {
            return $this->noStore(StorefrontError::respond($request, $refused->status, $refused->errorCode, $refused->getMessage(), $refused->details));
        }

        $availability = $this->stock->forSkus(array_column($items, 'sku'), $website->businessAccount);
        $reservable = $this->reservability->forQuote($website, $quote);
        $authoritative = $quote->authoritative();
        $now = CarbonImmutable::now();

        $codEnabled = $this->rules->codEnabled($website);
        $codMaximum = $this->rules->codMaximum($website, $quote->currency);

        $lines = [];

        foreach ($quote->lines as $position => $line) {
            /** @var WebsiteOrderLine $line */
            $lines[] = [
                ...$authoritative['items'][$position],
                'availability' => [
                    'in_stock' => (bool) ($availability[$line->sku]['in_stock'] ?? false),
                    'quantity' => (int) ($availability[$line->sku]['quantity'] ?? 0),
                    'reservable' => $reservable[$position],
                ],
            ];
        }

        return $this->noStore(new JsonResponse($this->withLegacyMoney([
            'data' => [
                'currency' => $quote->currency->value,
                'items' => $lines,
                'subtotal' => $authoritative['subtotal'],
                'discount' => $authoritative['discount'],
                'shipping' => $authoritative['shipping'],
                'tax' => $authoritative['tax'],
                'tax_included' => $quote->tax->includedTotal()->jsonSerialize(),
                'grand_total' => $authoritative['grand_total'],
                'delivery' => [
                    'basis' => 'flat',
                    'area_id' => $areaId,
                ],
                'payment' => [
                    'methods' => $this->rules->paymentMethods($website, $quote->currency),
                    'cod' => [
                        'enabled' => $codEnabled,
                        'eligible' => $this->rules->codAllows($website, $quote->total),
                        'maximum' => $codEnabled ? $codMaximum?->jsonSerialize() : null,
                    ],
                ],
                'quoted_at' => $now->toIso8601String(),
                'valid_until' => $now->addMinutes(self::VALID_FOR_MINUTES)->toIso8601String(),
            ],
        ])));
    }

    /**
     * A quote describes one customer's cart; no cache may keep it.
     */
    protected function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
