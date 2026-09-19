<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Order\Actions\CancelUnpaidOrder;
use App\Domain\Order\Actions\InitiateOrderPayment;
use App\Domain\Order\Actions\PlaceWholesaleOrder;
use App\Domain\Order\Enums\IntendedResaleChannel;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\UnpaidOrderCancellation;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\WholesaleOrderTracking;
use App\Http\Controllers\Controller;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Models\User;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * An account's own wholesale orders (§10.2, §14, P4-9–P4-12).
 *
 * **Self-scoped through the signed-in person's own account** (§31.3). There is no
 * account in any URL; an order is found among this account's wholesale orders or
 * not at all, so a reference belonging to anybody else is a 404 rather than a
 * refusal that confirms it exists.
 *
 * Placing an order reads only the fingerprint of the confirmed summary, a note
 * and a resale channel. Every figure is priced again on the server.
 *
 * Orders stay readable after the package stops including wholesale: an order
 * somebody paid for is theirs to follow whatever they buy next.
 */
class WholesaleOrderController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected WholesaleOrderTracking $tracking,
        protected ProductEligibility $eligibility,
    ) {}

    public function index(Request $request): Response
    {
        $account = $this->businessAccountFor($request);
        $orders = $this->tracking->paginate($account);

        return Inertia::render('wholesale/orders/index', [
            'orders' => [
                'data' => $orders->getCollection()->map(fn (Order $order) => $this->tracking->summary($order))->all(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Request $request, string $order): Response
    {
        $record = $this->orderFor($request, $order);

        return Inertia::render('wholesale/orders/show', [
            'order' => $this->tracking->detail($record),
            'can' => [
                'pay' => $this->canPay($record),
                'cancel' => $this->canCancel($request, $record),
            ],
        ]);
    }

    /**
     * Place the order for the confirmed checkout and send the person to pay (P4-9).
     */
    public function store(
        Request $request,
        PlaceWholesaleOrder $place,
        InitiateOrderPayment $initiate,
    ): SymfonyResponse {
        $account = $this->businessAccountFor($request);

        abort_unless($this->eligibility->allowsChannel($account, SalesChannel::Wholesale), 403);

        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
            'intended_resale_channel' => ['nullable', Rule::enum(IntendedResaleChannel::class)],
        ]);

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

        return $this->sendToGateway($request, $order, $initiate);
    }

    /**
     * Continue to payment for an order still waiting for it.
     */
    public function pay(Request $request, string $order, InitiateOrderPayment $initiate): SymfonyResponse
    {
        $account = $this->businessAccountFor($request);
        $record = $this->orderFor($request, $order);

        abort_unless($this->eligibility->allowsChannel($account, SalesChannel::Wholesale), 403);

        return $this->sendToGateway($request, $record, $initiate, refuseLoudly: true);
    }

    /**
     * Cancel an order nobody has paid for, giving its stock back.
     */
    public function cancel(Request $request, string $order, CancelUnpaidOrder $cancel): RedirectResponse
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('orders.flash.cancelled')]);

        return to_route('wholesale.orders.show', $record->public_id);
    }

    /**
     * Open the gateway session, or say on the order why it could not be.
     *
     * The gateway is another site, so an Inertia visit is told to leave with a
     * full page load rather than following a redirect it cannot read.
     *
     * An order that was placed stays placed when the gateway cannot be reached:
     * its page offers to continue to payment while the window is open.
     */
    protected function sendToGateway(
        Request $request,
        Order $order,
        InitiateOrderPayment $initiate,
        bool $refuseLoudly = false,
    ): SymfonyResponse {
        try {
            return Inertia::location($initiate->handle($order, $request));
        } catch (OrderRefused $refused) {
            if ($refuseLoudly) {
                throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
            }

            Inertia::flash('toast', ['type' => 'info', 'message' => $refused->getMessage()]);
        } catch (GatewayUnavailable|LockTimeout) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('orders.flash.gateway_unavailable')]);
        }

        return to_route('wholesale.orders.show', $order->public_id);
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

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
