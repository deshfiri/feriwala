<?php

namespace App\Http\Controllers\Api\Storefront\V1;

use App\Domain\Account\Exceptions\ResendTooSoon;
use App\Domain\Order\Actions\CancelUnpaidOrder;
use App\Domain\Order\Actions\ConfirmCodOrder;
use App\Domain\Order\Actions\InitiateOrderPayment;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\SendCodConfirmationCode;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\UnpaidOrderCancellation;
use App\Domain\Order\Exceptions\ConfirmationCodesExhausted;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\WebsiteOrderPayload;
use App\Domain\Website\Api\StorefrontError;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Models\Website;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\Localization\MobileNumber;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Money;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Orders a storefront submits, reads back and cancels (contract §5.3, §6.1,
 * P5-21, P5-23).
 *
 * The controller validates the shape, normalises the mobile number and hands
 * over: what an order costs, whether its stock can be held and whether it is a
 * duplicate are all decided server-side by {@see PlaceWebsiteOrder}. Every
 * refusal comes back in the contract's one error shape with its stable code.
 *
 * **Scoped by the credential, always.** Reading or cancelling another website's
 * order is a `404`: the API must not say whether an order exists elsewhere.
 */
class OrderController extends StorefrontController
{
    public function __construct(
        protected PlaceWebsiteOrder $orders,
        protected ConfirmCodOrder $confirmations,
        protected SendCodConfirmationCode $confirmationCodes,
        protected InitiateOrderPayment $sessions,
        protected CancelUnpaidOrder $cancellations,
        protected WebsiteOrderPayload $payload,
        protected MobileNumber $mobiles,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return $this->invalid($request, $validator->errors()->toArray());
        }

        /** @var array<string, mixed> $input */
        $input = $validator->validated();

        $skus = array_map(fn (array $item) => mb_strtoupper(trim($item['sku'])), $input['items']);

        if (count(array_unique($skus)) !== count($skus)) {
            return $this->invalid($request, ['items' => ['Each BPC may appear once; add the quantity to one line instead.']]);
        }

        $mobile = $this->mobiles->normalise((string) $input['customer']['phone']);

        if ($mobile === null) {
            return StorefrontError::respond($request, 422, 'invalid_mobile_number', 'The customer\'s mobile number could not be resolved.');
        }

        try {
            [$order, $placed] = $this->orders->handle($website, $this->submission($request, $input, $mobile));
        } catch (WebsiteOrderRefused $refused) {
            return $this->refused($request, $refused);
        }

        // Cash on delivery opens no gateway session: it is confirmed by the
        // customer and collected on delivery (§28).
        $redirect = $placed && ! $order->isCashOnDelivery() ? $this->openSession($order) : null;

        return $this->orderResponse($this->payload->for($order, $redirect), $placed ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $website = $this->website($request);

        $orders = $this->scoped($website)
            ->when(
                OrderStatus::tryFrom($request->string('status')->toString()) !== null,
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->when(
                strtotime($request->string('placed_after')->toString()) !== false,
                fn ($query) => $query->where('placed_at', '>', $request->string('placed_after')->toString()),
            )
            ->with(['items.stockReservation', 'payment', 'statusHistory'])
            ->orderByDesc('id')
            ->cursorPaginate($this->limit($request));

        return new JsonResponse($this->withLegacyMoney($this->envelope(
            $orders,
            $orders->getCollection()->map(fn (Order $order) => $this->payload->for($order))->all(),
        )));
    }

    public function show(Request $request, string $order): JsonResponse
    {
        $record = $this->find($this->website($request), $order);

        return $record === null
            ? $this->notFound($request)
            : $this->orderResponse($this->payload->for($record));
    }

    /**
     * Open a fresh gateway session for an order still waiting to be paid.
     *
     * A customer who closed the tab, or a session that could not be opened when
     * the order was taken, comes back through here rather than by placing the
     * order again.
     */
    public function paymentSession(Request $request, string $order): JsonResponse
    {
        $record = $this->find($this->website($request), $order);

        if ($record === null) {
            return $this->notFound($request);
        }

        $redirect = $this->openSession($record);

        if ($redirect === null) {
            return StorefrontError::respond(
                $request,
                409,
                'payment_not_open',
                'This order is not waiting for a payment that can be opened.',
            );
        }

        return new JsonResponse(['redirect_url' => $redirect]);
    }

    /**
     * Send the customer another code for a cash-on-delivery order (§6.2).
     *
     * The code never comes back here: it goes to the number the order was
     * placed with, and this says only when another may be asked for.
     */
    public function confirmationCode(Request $request, string $order): JsonResponse
    {
        $record = $this->find($this->website($request), $order);

        if ($record === null) {
            return $this->notFound($request);
        }

        if ($record->status !== OrderStatus::CustomerVerificationPending) {
            return $this->refused($request, WebsiteOrderRefused::notAwaitingConfirmation());
        }

        try {
            $this->confirmationCodes->handle($record);
        } catch (ResendTooSoon $tooSoon) {
            return $this->refused($request, WebsiteOrderRefused::confirmationTooSoon($tooSoon->secondsRemaining));
        } catch (ConfirmationCodesExhausted) {
            return $this->refused($request, WebsiteOrderRefused::confirmationCodesExhausted());
        }

        return $this->orderResponse($this->payload->for($record->refresh()), 202);
    }

    /**
     * The customer confirming a cash-on-delivery order with their code (§6.2).
     */
    public function confirm(Request $request, string $order): JsonResponse
    {
        $record = $this->find($this->website($request), $order);

        if ($record === null) {
            return $this->notFound($request);
        }

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:16'],
        ]);

        if ($validator->fails()) {
            return $this->invalid($request, $validator->errors()->toArray());
        }

        try {
            /** @var array{code: string} $input */
            $input = $validator->validated();

            $confirmed = $this->confirmations->handle($record, $input['code']);
        } catch (WebsiteOrderRefused $refused) {
            return $this->refused($request, $refused);
        }

        return $this->orderResponse($this->payload->for($confirmed));
    }

    /**
     * The customer changed their mind before paying (contract §6.1.2).
     */
    public function cancel(Request $request, string $order): JsonResponse
    {
        $record = $this->find($this->website($request), $order);

        if ($record === null) {
            return $this->notFound($request);
        }

        try {
            $cancelled = $this->cancellations->handle($record, UnpaidOrderCancellation::ByStorefront);
        } catch (OrderRefused|LockTimeout) {
            $cancelled = false;
        }

        if (! $cancelled && $record->refresh()->status !== OrderStatus::Cancelled) {
            return StorefrontError::respond(
                $request,
                409,
                'order_not_cancellable',
                'This order can no longer be cancelled. A paid order is refunded, not cancelled.',
            );
        }

        return $this->orderResponse($this->payload->for($record->refresh()));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function submission(Request $request, array $input, string $mobile): WebsiteOrderSubmission
    {
        $items = [];
        $prices = [];

        foreach (array_values($input['items']) as $item) {
            $items[] = ['sku' => mb_strtoupper(trim($item['sku'])), 'quantity' => (int) $item['quantity']];
            $prices[] = $this->money($item['unit_price']);
        }

        $shipping = $this->address($input['shipping_address']);

        return new WebsiteOrderSubmission(
            reference: trim((string) $input['storefront_order_reference']),
            idempotencyKey: trim((string) $request->header('Idempotency-Key')),
            customer: new WebsiteCustomerDetails(
                name: trim((string) $input['customer']['name']),
                mobile: $mobile,
                email: $input['customer']['email'] ?? null,
                reference: $input['customer']['storefront_customer_reference'] ?? null,
                isGuest: (bool) ($input['customer']['is_guest'] ?? true),
            ),
            shippingAddress: $shipping,
            billingAddress: isset($input['billing_address']) ? $this->address($input['billing_address']) : $shipping,
            items: $items,
            claimedUnitPrices: $prices,
            claimedTotals: [
                'subtotal' => $this->money($input['totals']['subtotal']),
                'discount' => $this->money($input['totals']['discount']),
                'shipping' => $this->money($input['totals']['shipping']),
                'tax' => $this->money($input['totals']['tax']),
                'grand_total' => $this->money($input['totals']['grand_total']),
            ],
            paymentMethod: (string) $input['payment']['method'],
            gateway: $input['payment']['gateway'] ?? null,
            claimedPaymentStatus: $input['payment']['status'] ?? null,
            returnUrl: $input['payment']['return_url'] ?? null,
            couponCode: $input['coupon_code'] ?? null,
            customerNote: $input['customer_note'] ?? null,
        );
    }

    /**
     * A money object as the storefront sent it — `amount` (preferred, flat
     * Taka) or `minor_units` (legacy poisha) — turned into a {@see Money}
     * (contract §4.1's minor-unit compatibility period).
     *
     * This is the one place a legacy minor-unit value is allowed to become a
     * Money; nothing behind it carries minor units (D26). The validation
     * rules already refuse a payload naming both or neither.
     *
     * @param  array<string, mixed>  $money
     */
    protected function money(array $money): Money
    {
        $currency = Currency::from((string) $money['currency']);

        if (array_key_exists('amount', $money)) {
            return DecimalAmount::parse((string) $money['amount'], $currency);
        }

        // Legacy poisha → flat Taka, once, at this one boundary.
        $decimal = bcdiv((string) (int) $money['minor_units'], '100', $currency->scale());

        return Money::fromDecimal($decimal, $currency);
    }

    /**
     * An order payload as the JSON response, with the frozen contract's
     * legacy `minor_units` and `decimal` keys added beside every money
     * object's flat-Taka `amount` (contract §4.1's minor-unit compatibility
     * period).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function orderResponse(array $payload, int $status = 200): JsonResponse
    {
        return new JsonResponse($this->withLegacyMoney($payload), $status);
    }

    /**
     * Every money object in an outbound payload, with the legacy keys added
     * beside it. The one place outbound legacy money keys are produced.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    protected function withLegacyMoney(array $payload): array
    {
        if ($this->isMoneyShape($payload)) {
            $amount = (string) $payload['amount'];
            assert(is_numeric($amount));

            return $payload + [
                // Exact: `amount` always has two decimal places here, so
                // ×100 never truncates anything a person typed or the ERP
                // computed.
                'minor_units' => (int) bcmul($amount, '100', 0),
                'decimal' => $amount,
            ];
        }

        return array_map(
            fn (mixed $value) => is_array($value) ? $this->withLegacyMoney($value) : $value,
            $payload,
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    protected function isMoneyShape(array $value): bool
    {
        return array_key_exists('amount', $value)
            && array_key_exists('currency', $value)
            && array_key_exists('formatted', $value);
    }

    /**
     * The address as the order keeps it: what was sent, and nothing else.
     *
     * @param  array<string, mixed>  $address
     * @return array<string, string|null>
     */
    protected function address(array $address): array
    {
        $fields = ['line1', 'line2', 'city', 'district', 'postcode', 'country'];
        $snapshot = [];

        foreach ($fields as $field) {
            $value = $address[$field] ?? null;
            $snapshot[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $snapshot;
    }

    /**
     * A gateway session for the order, or null when one cannot be opened now.
     *
     * An unreachable gateway never fails an order that has already been taken:
     * the stock is held, the window is open, and the storefront asks again.
     */
    protected function openSession(Order $order): ?string
    {
        try {
            return $this->sessions->handle($order);
        } catch (OrderRefused|GatewayUnavailable|ConnectionException|LockTimeout) {
            return null;
        }
    }

    /**
     * @return Builder<Order>
     */
    protected function scoped(Website $website)
    {
        return Order::query()
            ->where('source', OrderSource::Website)
            ->where('website_id', $website->id);
    }

    protected function find(Website $website, string $publicId): ?Order
    {
        /** @var Order|null $order */
        $order = $this->scoped($website)->where('public_id', $publicId)->first();

        return $order;
    }

    protected function refused(Request $request, WebsiteOrderRefused $refused): JsonResponse
    {
        return StorefrontError::respond($request, $refused->status, $refused->errorCode, $refused->getMessage(), $refused->details);
    }

    protected function notFound(Request $request): JsonResponse
    {
        return StorefrontError::respond($request, 404, 'not_found', 'No such order on this website.');
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    protected function invalid(Request $request, array $errors): JsonResponse
    {
        return StorefrontError::respond($request, 422, 'validation_failed', 'The order could not be read.', ['fields' => $errors]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        // Exactly one of `amount` (preferred, flat Taka) or `minor_units`
        // (legacy poisha) per money object — never both, never neither
        // (contract §4.1's minor-unit compatibility period).
        $money = fn (string $field) => [
            $field => ['required', 'array'],
            $field.'.amount' => ['required_without:'.$field.'.minor_units', 'prohibits:'.$field.'.minor_units', new DecimalAmountRule],
            $field.'.minor_units' => ['required_without:'.$field.'.amount', 'prohibits:'.$field.'.amount', 'integer', 'min:0'],
            $field.'.currency' => ['required', 'string', 'in:'.implode(',', array_column(Currency::cases(), 'value'))],
        ];

        return array_merge([
            'storefront_order_reference' => ['required', 'string', 'max:64'],
            'placed_at' => ['sometimes', 'date'],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:160'],
            'customer.phone' => ['required', 'string', 'max:32'],
            'customer.email' => ['nullable', 'email', 'max:254'],
            'customer.storefront_customer_reference' => ['nullable', 'string', 'max:64'],
            'customer.is_guest' => ['sometimes', 'boolean'],
            'shipping_address' => ['required', 'array'],
            'shipping_address.line1' => ['required', 'string', 'max:255'],
            'shipping_address.line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['required', 'string', 'max:120'],
            'shipping_address.district' => ['nullable', 'string', 'max:120'],
            'shipping_address.postcode' => ['nullable', 'string', 'max:20'],
            'shipping_address.country' => ['required', 'string', 'size:2'],
            'billing_address' => ['sometimes', 'nullable', 'array'],
            'billing_address.line1' => ['required_with:billing_address', 'string', 'max:255'],
            'billing_address.city' => ['required_with:billing_address', 'string', 'max:120'],
            'billing_address.country' => ['required_with:billing_address', 'string', 'size:2'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.sku' => ['required', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_price' => ['required', 'array'],
            'totals' => ['required', 'array'],
            'payment' => ['required', 'array'],
            'payment.method' => ['required', 'string', 'max:20'],
            'payment.gateway' => ['nullable', 'string', 'max:40'],
            'payment.status' => ['nullable', 'string', 'max:20'],
            'payment.gateway_reference' => ['nullable', 'string', 'max:120'],
            'payment.return_url' => ['nullable', 'string', 'url', 'max:2048'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
        ],
            $money('items.*.unit_price'),
            $money('totals.subtotal'),
            $money('totals.discount'),
            $money('totals.shipping'),
            $money('totals.tax'),
            $money('totals.grand_total'),
        );
    }
}
