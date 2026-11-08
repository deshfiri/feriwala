<?php

namespace App\Http\Controllers\Api\Mobile\V1\Wholesale;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Order\Actions\CancelUnpaidOrder;
use App\Domain\Order\Actions\ChangeOrderPaymentMethod;
use App\Domain\Order\Actions\InitiateOrderPayment;
use App\Domain\Order\Actions\PayOrderFromWallet;
use App\Domain\Order\Actions\PlaceWholesaleOrder;
use App\Domain\Order\Enums\IntendedResaleChannel;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\UnpaidOrderCancellation;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\WholesaleOrderTracking;
use App\Domain\Wallet\WalletPaymentMethod;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Wholesale\Concerns\GuardsWholesaleFacility;
use App\Http\Requests\Mobile\Wholesale\PayOrderRequest;
use App\Http\Requests\Mobile\Wholesale\PlaceOrderRequest;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\GatewayNavigation;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * An account's own wholesale orders (§10.2, §14).
 *
 * Self-scoped through the signed-in person's own account (§31.3): an order is
 * found among this account's wholesale orders or not at all, so another
 * account's order is a `404`. Placing reads only the fingerprint of the confirmed
 * summary, a note and a resale channel; every figure is priced again on the
 * server. Orders stay readable after the package stops including wholesale.
 *
 * Payment is the web's own: the same Actions place the order, open the gateway
 * session and pay from the wallet, so the transaction, row locks, idempotency
 * and ledger entry are theirs. The gateway is opened by the app in a browser
 * view at `redirect_url`.
 */
class WholesaleOrderController extends MobileController
{
    use GuardsWholesaleFacility;
    use ResolvesBusinessAccount;

    public function __construct(
        protected WholesaleOrderTracking $tracking,
        protected ProductEligibility $eligibility,
        protected PaymentGatewayManager $gateways,
    ) {}

    /**
     * List orders
     *
     * The account's wholesale orders, newest first, 20 to a page.
     *
     * @response array{data: list<array<string, mixed>>, links: array<string, string|null>, meta: array<string, mixed>}
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $this->tracking->paginate($this->businessAccountFor($request));

        return JsonResource::collection(
            $orders->through(fn (Order $order) => $this->tracking->summary($order)),
        );
    }

    /**
     * Order detail
     *
     * One order in full, with `payment_methods` the buyer can switch to while it
     * still waits for payment and `can` saying whether it can be paid or cancelled.
     * An order that is not this account's answers `404`.
     *
     * @response array{data: array<string, mixed>, payment_methods: list<array<string, mixed>>, can: array{pay: bool, cancel: bool}}
     */
    public function show(Request $request, string $order): JsonResponse
    {
        return new JsonResponse($this->present($request, $this->orderFor($request, $order)));
    }

    /**
     * Place the order
     *
     * Places the order for the confirmed checkout and starts its payment. Only
     * `fingerprint`, `customer_note` and `intended_resale_channel` are read; any
     * amount sent is ignored. Placing is idempotent per confirmation: a repeat
     * returns the same order with `200`. `redirect_url` is where to open the
     * gateway; it is null when the wallet paid, or when the gateway could not be
     * opened (the order stays placed and `message` says why; continue with
     * `POST /wholesale/orders/{order}/payment`). Errors: `403`
     * `wholesale_not_allowed`; `422` when the summary changed, the cart is not
     * ready, stock is short or a KYC re-verification blocks new orders.
     *
     * @response 201 array{data: array<string, mixed>, payment_methods: list<array<string, mixed>>, can: array{pay: bool, cancel: bool}, redirect_url: string|null, message: string|null}
     */
    public function store(
        PlaceOrderRequest $request,
        PlaceWholesaleOrder $place,
        InitiateOrderPayment $initiate,
        PayOrderFromWallet $wallet,
    ): JsonResponse {
        $account = $this->businessAccountFor($request);
        $this->requireWholesale($account);

        $validated = $request->validated();

        try {
            $order = $place->handle(
                $this->person($request),
                $account,
                $validated['fingerprint'],
                $validated['customer_note'] ?? null,
                isset($validated['intended_resale_channel']) ? IntendedResaleChannel::from($validated['intended_resale_channel']) : null,
            );
        } catch (OrderRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        $settled = $this->settle($request, $order, $initiate, $wallet, refuseLoudly: false);

        return new JsonResponse(
            [...$this->present($request, $order->refresh()), ...$settled],
            $order->wasRecentlyCreated ? 201 : 200,
        );
    }

    /**
     * Continue to payment
     *
     * Continues to payment for an order still waiting for it, optionally with a
     * different `payment_method` (a `name` from the order's `payment_methods`).
     * Answers with `redirect_url` to open the gateway, or null once the wallet
     * has paid. Paying from the wallet twice does not charge twice. Errors:
     * `403` `wholesale_not_allowed`; `404`; `422` when the order cannot be paid
     * any more, the method is unavailable or the wallet cannot cover it; `503`
     * `gateway_unavailable` when the gateway cannot be reached.
     *
     * @response array{data: array<string, mixed>, payment_methods: list<array<string, mixed>>, can: array{pay: bool, cancel: bool}, redirect_url: string|null, message: string|null}
     */
    public function pay(
        PayOrderRequest $request,
        string $order,
        InitiateOrderPayment $initiate,
        ChangeOrderPaymentMethod $change,
        PayOrderFromWallet $wallet,
    ): JsonResponse {
        $account = $this->businessAccountFor($request);
        $record = $this->orderFor($request, $order);

        $this->requireWholesale($account);

        $method = $request->validated('payment_method');

        if (filled($method)) {
            try {
                $change->handle($record, $method, $request);
            } catch (OrderRefused $refused) {
                throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
            } catch (LockTimeout) {
                throw ValidationException::withMessages(['order' => __('orders.refused.busy')]);
            }

            $record = $record->refresh();
        }

        $settled = $this->settle($request, $record, $initiate, $wallet, refuseLoudly: true);

        if ($settled['redirect_url'] === null && $settled['message'] !== null && $record->refresh()->status !== OrderStatus::Paid) {
            return new JsonResponse([
                'code' => 'gateway_unavailable',
                'message' => $settled['message'],
            ], 503);
        }

        return new JsonResponse([...$this->present($request, $record->refresh()), ...$settled]);
    }

    /**
     * Cancel an unpaid order
     *
     * Cancels an order nobody has paid for, giving its stock back. `404` for an
     * order that is not this account's; `403` when the person may not cancel it;
     * `422` when it can no longer be cancelled.
     *
     * @response array{data: array<string, mixed>, payment_methods: list<array<string, mixed>>, can: array{pay: bool, cancel: bool}}
     */
    public function cancel(Request $request, string $order, CancelUnpaidOrder $cancel): JsonResponse
    {
        $record = $this->orderFor($request, $order);

        Gate::authorize('cancel', $record);

        try {
            $cancel->handle($record, UnpaidOrderCancellation::ByAccount, $this->person($request));
        } catch (OrderRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        } catch (LockTimeout) {
            throw ValidationException::withMessages(['order' => __('orders.refused.busy')]);
        }

        return new JsonResponse($this->present($request, $this->orderFor($request, $order)));
    }

    /**
     * Take the payment on its way: from the wallet, or by opening the gateway.
     *
     * An order that was placed stays placed when payment could not start.
     *
     * @return array{redirect_url: string|null, message: string|null}
     */
    protected function settle(
        Request $request,
        Order $order,
        InitiateOrderPayment $initiate,
        PayOrderFromWallet $wallet,
        bool $refuseLoudly,
    ): array {
        if ($this->settlesWithoutGateway($order)) {
            try {
                $wallet->handle($order, $this->person($request));

                return ['redirect_url' => null, 'message' => __('wallet.pay.paid')];
            } catch (OrderRefused $refused) {
                if ($refuseLoudly) {
                    throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
                }

                return ['redirect_url' => null, 'message' => $refused->getMessage()];
            } catch (LockTimeout) {
                throw ValidationException::withMessages(['order' => __('orders.refused.busy')]);
            }
        }

        try {
            $url = $initiate->handle($order, $request);

            GatewayNavigation::ensureOpenable($url, (string) $order->payment?->gateway);

            return ['redirect_url' => $url, 'message' => null];
        } catch (OrderRefused $refused) {
            if ($refuseLoudly) {
                throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
            }

            return ['redirect_url' => null, 'message' => $refused->getMessage()];
        } catch (GatewayUnavailable|LockTimeout) {
            return ['redirect_url' => null, 'message' => __('orders.flash.gateway_unavailable')];
        }
    }

    /**
     * Whether no gateway is involved: the order is paid from the wallet, or an
     * order placed under the earlier delivery-only rule has nothing to pay.
     */
    protected function settlesWithoutGateway(Order $order): bool
    {
        $payment = $order->payment;

        return $payment !== null
            && (WalletPaymentMethod::is($payment->gateway)
                || (! $order->usesFullPaymentModel() && ! $payment->amount->isPositive()));
    }

    /**
     * @return array{data: array<string, mixed>, payment_methods: list<array<string, mixed>>, can: array{pay: bool, cancel: bool}}
     */
    protected function present(Request $request, Order $order): array
    {
        $canPay = $this->canPay($order);

        return [
            'data' => $this->tracking->detail($order),
            'payment_methods' => $canPay ? $this->methodsFor($order) : [],
            'can' => [
                'pay' => $canPay,
                'cancel' => $this->canCancel($request, $order),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function methodsFor(Order $order): array
    {
        $amount = $order->payment?->amount;

        if ($amount === null) {
            return [];
        }

        $gateways = array_map(function (string $name) use ($amount): array {
            $label = config("payment.gateways.{$name}.label");

            return [
                'name' => $name,
                'label' => is_string($label) && $label !== '' ? $label : $name,
                'fx' => $this->gateways->conversionNotice($name, $amount->currency, $amount),
                'balance' => null,
                'sufficient' => true,
            ];
        }, $this->gateways->availableFor($amount->currency));

        return [...$gateways, WalletPaymentMethod::offer($order->businessAccount, $amount)];
    }

    protected function canPay(Order $order): bool
    {
        $payment = $order->payment;

        return $order->status === OrderStatus::PaymentPending
            && $payment !== null
            && in_array($payment->status, [PaymentStatus::Draft, PaymentStatus::Initiated], true)
            && $payment->expires_at !== null
            && $payment->expires_at->isFuture();
    }

    protected function canCancel(Request $request, Order $order): bool
    {
        return $order->status === OrderStatus::PaymentPending
            && $order->payment?->status !== PaymentStatus::Pending
            && ! ($order->payment?->status->isSettled() ?? false)
            && $this->person($request)->can('cancel', $order);
    }

    protected function orderFor(Request $request, string $order): Order
    {
        $record = $this->tracking->find($this->businessAccountFor($request), $order);

        abort_if($record === null, 404);

        return $record;
    }
}
