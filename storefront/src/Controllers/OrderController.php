<?php

declare(strict_types=1);

namespace Storefront\Controllers;

use Storefront\App;
use Storefront\Domain\Orders;
use Storefront\Erp\ErpException;
use Storefront\Erp\Signature;
use Storefront\Http\Request;
use Storefront\Http\Response;
use Storefront\Http\Session;
use Storefront\Http\View;
use Storefront\Kernel;
use Storefront\Support\Throttle;

/**
 * An order after it is placed: seeing it, paying for it, confirming a
 * cash-on-delivery one, cancelling it, asking to send goods back.
 *
 * Every action is on an order the visitor was *granted* — by the link that
 * carries its token, or by having placed it in this session. Holding a mobile
 * number grants nothing (contract §6.2).
 */
final class OrderController
{
    /**
     * @return array<string, mixed>|null the local row, when the visitor may act on it
     */
    private static function granted(Request $request, string $publicId): ?array
    {
        $grants = (array) Session::get('orders', []);
        $token = $request->string('t');
        $order = Orders::authorised($publicId, $token !== '' ? $token : null, isset($grants[$publicId]) ? (string) $grants[$publicId] : null);

        // A link with the token also grants it for the rest of this session.
        if ($order !== null && $token !== '') {
            $grants[$publicId] = (string) $order['access_token'];
            Session::put('orders', $grants);
        }

        return $order;
    }

    public static function show(Request $request, string $id): Response
    {
        $local = self::granted($request, $id);

        if ($local === null) {
            return View::render('errors/404', [], true, 404);
        }

        $order = null;
        $stale = false;

        try {
            $order = Orders::fetch($id);
        } catch (ErpException) {
            $stale = true;
        }

        if ($order === null && ! $stale) {
            return View::render('errors/404', [], true, 404);
        }

        $order ??= [
            'id' => $local['public_id'],
            'reference' => $local['reference'],
            'status' => $local['status'],
            'items' => [],
            'timeline' => [],
            'payment' => ['method' => $local['payment_method']],
            'totals' => ['grand_total' => ['amount' => $local['grand_total'], 'currency' => $local['currency']]],
            'placed_at' => str_replace(' ', 'T', (string) $local['placed_at']).'+00:00',
        ];

        return View::render('orders/show', [
            'title' => t('order.title', ['ref' => (string) ($order['reference'] ?? '')]),
            'order' => $order,
            'local' => $local,
            'stale' => $stale,
            'paymentReturn' => $request->string('payment'),
            'idempotency' => Signature::nonce(),
            'noindex' => true,
        ]);
    }

    /**
     * Open a fresh gateway session for an order still waiting to be paid.
     */
    public static function pay(Request $request, string $id): Response
    {
        $local = self::granted($request, $id);

        if ($local === null) {
            return View::render('errors/404', [], true, 404);
        }

        try {
            $response = App::erp()->post('/orders/'.rawurlencode($id).'/payment-session', []);
        } catch (ErpException $exception) {
            Session::flash('error', t($exception->is('payment_not_open') ? 'order.payment_not_open' : 'order.payment_failed'));

            return Response::redirect(self::link($local));
        }

        $url = is_string($response['redirect_url'] ?? null) ? $response['redirect_url'] : '';

        if (! str_starts_with($url, 'https://')) {
            Session::flash('error', t('order.payment_failed'));

            return Response::redirect(self::link($local));
        }

        return Response::redirect($url);
    }

    public static function cancel(Request $request, string $id): Response
    {
        $local = self::granted($request, $id);

        if ($local === null) {
            return View::render('errors/404', [], true, 404);
        }

        try {
            App::erp()->post('/orders/'.rawurlencode($id).'/cancellation', [], Signature::nonce());
            Session::flash('success', t('order.cancelled'));
        } catch (ErpException $exception) {
            Session::flash('error', t($exception->is('order_not_cancellable') ? 'order.not_cancellable' : 'order.action_failed'));
        }

        return Response::redirect(self::link($local));
    }

    /**
     * Ask for a cash-on-delivery confirmation code. The code goes to the
     * customer's own phone and never comes back here (§6.1.3).
     */
    public static function sendCode(Request $request, string $id): Response
    {
        $local = self::granted($request, $id);

        if ($local === null) {
            return View::render('errors/404', [], true, 404);
        }

        try {
            App::erp()->post('/orders/'.rawurlencode($id).'/confirmation/code', [], Signature::nonce());
            Session::flash('success', t('order.code_sent'));
        } catch (ErpException $exception) {
            $key = match ($exception->errorCode) {
                'confirmation_code_too_soon' => 'order.code_too_soon',
                'confirmation_code_limit_reached' => 'order.code_limit',
                'confirmation_expired' => 'order.code_expired',
                default => 'order.action_failed',
            };

            Session::flash('error', t($key, ['seconds' => (string) ($exception->retryAfter ?? ($exception->details['retry_after'] ?? 60))]));
        }

        return Response::redirect(self::link($local));
    }

    public static function confirm(Request $request, string $id): Response
    {
        $local = self::granted($request, $id);

        if ($local === null) {
            return View::render('errors/404', [], true, 404);
        }

        $code = preg_replace('/\D/', '', $request->string('code')) ?? '';

        if (strlen($code) < 4 || strlen($code) > 8) {
            Session::flash('error', t('order.code_invalid'));

            return Response::redirect(self::link($local));
        }

        try {
            App::erp()->post('/orders/'.rawurlencode($id).'/confirmation', ['code' => $code], Signature::nonce());
            Session::flash('success', t('order.confirmed'));
        } catch (ErpException $exception) {
            // A wrong, spent or never-issued code get one answer on purpose.
            $key = match ($exception->errorCode) {
                'confirmation_expired' => 'order.code_expired',
                'confirmation_refused' => 'order.code_invalid',
                default => 'order.action_failed',
            };

            Session::flash('error', t($key));
        }

        return Response::redirect(self::link($local));
    }

    public static function returnForm(Request $request, string $id): Response
    {
        $local = self::granted($request, $id);

        if ($local === null) {
            return View::render('errors/404', [], true, 404);
        }

        try {
            $order = Orders::fetch($id);
        } catch (ErpException $exception) {
            return Kernel::unavailable($exception);
        }

        $returnable = is_array($order['returnable'] ?? null) ? $order['returnable'] : [];

        if (! ($returnable['eligible'] ?? false)) {
            Session::flash('info', t('return.not_eligible'));

            return Response::redirect(self::link($local));
        }

        return View::render('orders/return', [
            'title' => t('return.title'),
            'order' => $order,
            'local' => $local,
            'returnable' => $returnable,
            'idempotency' => Signature::nonce(),
            'errors' => Session::get('_return_errors', []),
            'noindex' => true,
        ]);
    }

    public static function returnSubmit(Request $request, string $id): Response
    {
        $local = self::granted($request, $id);

        if ($local === null) {
            return View::render('errors/404', [], true, 404);
        }

        $reasons = ['damaged', 'wrong_item', 'not_as_described', 'missing_parts', 'changed_mind', 'other'];
        $reason = $request->string('reason');
        $lines = [];

        foreach ((array) ($request->post['qty'] ?? []) as $sku => $quantity) {
            $quantity = (int) $quantity;

            if (is_string($sku) && $quantity > 0) {
                $lines[] = ['sku' => $sku, 'quantity' => $quantity];
            }
        }

        $key = $request->string('idempotency_key');

        if (! in_array($reason, $reasons, true) || $lines === [] || preg_match('/^[0-9a-f-]{36}$/', $key) !== 1) {
            Session::put('_return_errors', ['form' => 'return.invalid']);

            return Response::redirect('/order/'.rawurlencode($id).'/return');
        }

        Session::forget('_return_errors');

        try {
            App::erp()->post('/orders/'.rawurlencode($id).'/return-requests', [
                'reason' => $reason,
                'lines' => $lines,
                'customer_note' => mb_substr($request->string('note'), 0, 1000) ?: null,
            ], $key);

            Session::flash('success', t('return.requested'));
        } catch (ErpException $exception) {
            $messages = [
                'order_not_returnable' => 'return.not_returnable',
                'return_window_closed' => 'return.window_closed',
                'quantity_not_returnable' => 'return.quantity',
                'line_not_on_order' => 'return.invalid',
                'nothing_returnable' => 'return.nothing',
            ];

            Session::flash('error', t($messages[$exception->errorCode] ?? 'order.action_failed'));
        }

        return Response::redirect(self::link($local));
    }

    /**
     * Where the gateway sends the customer back to. Nothing in the query is
     * believed: the order is read from the ERP.
     */
    public static function paymentReturn(Request $request): Response
    {
        $id = preg_replace('/[^0-9A-Za-z]/', '', $request->string('order')) ?? '';
        $grants = (array) Session::get('orders', []);

        if ($id !== '' && isset($grants[$id])) {
            $outcome = preg_replace('/[^a-z_]/', '', strtolower($request->string('payment'))) ?? '';

            return Response::redirect('/order/'.rawurlencode($id).'?payment='.$outcome);
        }

        // Somebody who arrives without the session that placed it is not shown
        // the order, only how to find it.
        Session::flash('info', t('order.return_find'));

        return Response::redirect('/track');
    }

    public static function trackForm(Request $request): Response
    {
        return View::render('orders/track', ['title' => t('track.title'), 'noindex' => true]);
    }

    public static function trackSubmit(Request $request): Response
    {
        $reference = mb_substr($request->string('reference'), 0, 64);
        $mobile = mb_substr($request->string('mobile'), 0, 32);

        // Ten tries in ten minutes from one address, and five per reference:
        // enough for a typo, not enough to walk a number space.
        $allowed = Throttle::hit('track:ip:'.$request->ip(), 10, 600)
            && Throttle::hit('track:ref:'.strtolower($reference), 5, 600);

        if (! $allowed) {
            Session::flash('error', t('track.too_many'));

            return Response::redirect('/track');
        }

        $order = Orders::lookup($reference, $mobile);

        if ($order === null) {
            Session::flash('error', t('track.not_found'));

            return Response::redirect('/track');
        }

        $grants = (array) Session::get('orders', []);
        $grants[(string) $order['public_id']] = (string) $order['access_token'];
        Session::put('orders', $grants);

        return Response::redirect('/order/'.rawurlencode((string) $order['public_id']));
    }

    /**
     * @param  array<string, mixed>  $local
     */
    private static function link(array $local): string
    {
        return '/order/'.rawurlencode((string) $local['public_id']);
    }
}
