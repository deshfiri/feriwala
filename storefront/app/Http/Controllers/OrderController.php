<?php

namespace App\Http\Controllers;

use App\Erp\Client;
use App\Erp\ErpException;
use App\Erp\Signature;
use App\Models\Order;
use App\Services\Orders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * An order after it is placed: seeing it, paying for it, confirming a
 * cash-on-delivery one, cancelling it, asking to send goods back.
 *
 * Every action is on an order the visitor was *granted* — by the link that
 * carries its token, or by having placed it in this session. Holding a mobile
 * number grants nothing (contract §6.2).
 */
class OrderController extends Controller
{
    public function __construct(private readonly Orders $orders, private readonly Client $erp) {}

    /**
     * The local row, when the visitor may act on it; a 404 otherwise.
     */
    private function granted(Request $request, string $publicId): Order
    {
        $grants = (array) $request->session()->get('orders', []);
        $token = request_string($request, 't');
        $order = $this->orders->authorised($publicId, $token !== '' ? $token : null, isset($grants[$publicId]) ? (string) $grants[$publicId] : null);

        abort_if($order === null, 404);

        // A link with the token also grants it for the rest of this session.
        if ($token !== '') {
            $grants[$publicId] = $order->access_token;
            $request->session()->put('orders', $grants);
        }

        return $order;
    }

    public function show(Request $request, string $id): View
    {
        $local = $this->granted($request, $id);

        $order = null;
        $stale = false;

        try {
            $order = $this->orders->fetch($id);
        } catch (ErpException) {
            $stale = true;
        }

        abort_if($order === null && ! $stale, 404);

        $order ??= [
            'id' => $local->public_id,
            'reference' => $local->reference,
            'status' => $local->status,
            'items' => [],
            'timeline' => [],
            'payment' => ['method' => $local->payment_method],
            'totals' => ['grand_total' => ['amount' => $local->grand_total, 'currency' => $local->currency]],
            'placed_at' => str_replace(' ', 'T', (string) $local->placed_at).'+00:00',
        ];

        return view('orders.show', [
            'title' => t('order.title', ['ref' => (string) ($order['reference'] ?? '')]),
            'order' => $order,
            'local' => $local,
            'stale' => $stale,
            'paymentReturn' => request_string($request, 'payment'),
            'idempotency' => Signature::nonce(),
            'noindex' => true,
        ]);
    }

    /**
     * Open a fresh gateway session for an order still waiting to be paid.
     */
    public function pay(Request $request, string $id): RedirectResponse
    {
        $local = $this->granted($request, $id);

        try {
            $response = $this->erp->post('/orders/'.rawurlencode($id).'/payment-session', []);
        } catch (ErpException $exception) {
            flash_message('error', t($exception->is('payment_not_open') ? 'order.payment_not_open' : 'order.payment_failed'));

            return $this->backTo($local);
        }

        $url = is_string($response['redirect_url'] ?? null) ? $response['redirect_url'] : '';

        if (! str_starts_with($url, 'https://')) {
            flash_message('error', t('order.payment_failed'));

            return $this->backTo($local);
        }

        return redirect()->away($url);
    }

    public function cancel(Request $request, string $id): RedirectResponse
    {
        $local = $this->granted($request, $id);

        try {
            $this->erp->post('/orders/'.rawurlencode($id).'/cancellation', [], Signature::nonce());
            flash_message('success', t('order.cancelled'));
        } catch (ErpException $exception) {
            flash_message('error', t($exception->is('order_not_cancellable') ? 'order.not_cancellable' : 'order.action_failed'));
        }

        return $this->backTo($local);
    }

    /**
     * Ask for a cash-on-delivery confirmation code. The code goes to the
     * customer's own phone and never comes back here (§6.1.3).
     */
    public function sendCode(Request $request, string $id): RedirectResponse
    {
        $local = $this->granted($request, $id);

        try {
            $this->erp->post('/orders/'.rawurlencode($id).'/confirmation/code', [], Signature::nonce());
            flash_message('success', t('order.code_sent'));
        } catch (ErpException $exception) {
            $key = match ($exception->errorCode) {
                'confirmation_code_too_soon' => 'order.code_too_soon',
                'confirmation_code_limit_reached' => 'order.code_limit',
                'confirmation_expired' => 'order.code_expired',
                default => 'order.action_failed',
            };

            flash_message('error', t($key, ['seconds' => (string) ($exception->retryAfter ?? ($exception->details['retry_after'] ?? 60))]));
        }

        return $this->backTo($local);
    }

    public function confirm(Request $request, string $id): RedirectResponse
    {
        $local = $this->granted($request, $id);

        $code = preg_replace('/\D/', '', request_string($request, 'code')) ?? '';

        if (strlen($code) < 4 || strlen($code) > 8) {
            flash_message('error', t('order.code_invalid'));

            return $this->backTo($local);
        }

        try {
            $this->erp->post('/orders/'.rawurlencode($id).'/confirmation', ['code' => $code], Signature::nonce());
            flash_message('success', t('order.confirmed'));
        } catch (ErpException $exception) {
            // A wrong, spent or never-issued code get one answer on purpose.
            $key = match ($exception->errorCode) {
                'confirmation_expired' => 'order.code_expired',
                'confirmation_refused' => 'order.code_invalid',
                default => 'order.action_failed',
            };

            flash_message('error', t($key));
        }

        return $this->backTo($local);
    }

    public function returnForm(Request $request, string $id): View|RedirectResponse
    {
        $local = $this->granted($request, $id);

        $order = $this->orders->fetch($id);

        abort_if($order === null, 404);

        $returnable = is_array($order['returnable'] ?? null) ? $order['returnable'] : [];

        if (! ($returnable['eligible'] ?? false)) {
            flash_message('info', t('return.not_eligible'));

            return $this->backTo($local);
        }

        return view('orders.return', [
            'title' => t('return.title'),
            'order' => $order,
            'local' => $local,
            'returnable' => $returnable,
            'idempotency' => Signature::nonce(),
            'noindex' => true,
        ]);
    }

    public function returnSubmit(Request $request, string $id): RedirectResponse
    {
        $local = $this->granted($request, $id);

        $reasons = ['damaged', 'wrong_item', 'not_as_described', 'missing_parts', 'changed_mind', 'other'];
        $reason = request_string($request, 'reason');
        $lines = [];

        foreach ((array) $request->input('qty', []) as $sku => $quantity) {
            $quantity = (int) $quantity;

            if (is_string($sku) && $quantity > 0) {
                $lines[] = ['sku' => $sku, 'quantity' => $quantity];
            }
        }

        $key = request_string($request, 'idempotency_key');

        if (! in_array($reason, $reasons, true) || $lines === [] || preg_match('/^[0-9a-f-]{36}$/', $key) !== 1) {
            return redirect()
                ->route('orders.return', ['id' => $id, 't' => $local->access_token])
                ->withErrors(['form' => t('return.invalid')]);
        }

        try {
            $this->erp->post('/orders/'.rawurlencode($id).'/return-requests', [
                'reason' => $reason,
                'lines' => $lines,
                'customer_note' => mb_substr(request_string($request, 'note'), 0, 1000) ?: null,
            ], $key);

            flash_message('success', t('return.requested'));
        } catch (ErpException $exception) {
            $messages = [
                'order_not_returnable' => 'return.not_returnable',
                'return_window_closed' => 'return.window_closed',
                'quantity_not_returnable' => 'return.quantity',
                'line_not_on_order' => 'return.invalid',
                'nothing_returnable' => 'return.nothing',
            ];

            flash_message('error', t($messages[$exception->errorCode] ?? 'order.action_failed'));
        }

        return $this->backTo($local);
    }

    /**
     * Where the gateway sends the customer back to. Nothing in the query is
     * believed: the order is read from the ERP.
     */
    public function paymentReturn(Request $request): RedirectResponse
    {
        $id = preg_replace('/[^0-9A-Za-z]/', '', request_string($request, 'order')) ?? '';
        $grants = (array) $request->session()->get('orders', []);

        if ($id !== '' && isset($grants[$id])) {
            $outcome = preg_replace('/[^a-z_]/', '', strtolower(request_string($request, 'payment'))) ?? '';

            return redirect()->route('orders.show', ['id' => $id, 'payment' => $outcome]);
        }

        // Somebody who arrives without the session that placed it is not shown
        // the order, only how to find it.
        flash_message('info', t('order.return_find'));

        return redirect()->route('track.form');
    }

    public function trackForm(): View
    {
        return view('orders.track', ['title' => t('track.title'), 'noindex' => true]);
    }

    public function trackSubmit(Request $request): RedirectResponse
    {
        $reference = mb_substr(request_string($request, 'reference'), 0, 64);
        $mobile = mb_substr(request_string($request, 'mobile'), 0, 32);

        // Ten tries in ten minutes from one address, and five per reference:
        // enough for a typo, not enough to walk a number space.
        $allowed = RateLimiter::attempt('track:ip:'.$request->ip(), 10, fn () => true, 600) !== false
            && RateLimiter::attempt('track:ref:'.strtolower($reference), 5, fn () => true, 600) !== false;

        if (! $allowed) {
            flash_message('error', t('track.too_many'));

            return redirect()->route('track.form');
        }

        $order = $this->orders->lookup($reference, $mobile);

        if ($order === null) {
            flash_message('error', t('track.not_found'));

            return redirect()->route('track.form');
        }

        $grants = (array) $request->session()->get('orders', []);
        $grants[$order->public_id] = $order->access_token;
        $request->session()->put('orders', $grants);

        return redirect()->route('orders.show', ['id' => $order->public_id]);
    }

    private function backTo(Order $local): RedirectResponse
    {
        return redirect()->route('orders.show', ['id' => $local->public_id]);
    }
}
