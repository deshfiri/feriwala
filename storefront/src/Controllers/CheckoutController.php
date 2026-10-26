<?php

declare(strict_types=1);

namespace Storefront\Controllers;

use Storefront\Domain\Checkout;
use Storefront\Domain\Orders;
use Storefront\Domain\Site;
use Storefront\Erp\ErpException;
use Storefront\Http\Request;
use Storefront\Http\Response;
use Storefront\Http\Session;
use Storefront\Http\View;
use Storefront\Kernel;

final class CheckoutController
{
    public static function form(Request $request): Response
    {
        $cart = CartController::cart($request);

        if ($cart->isEmpty()) {
            Session::flash('info', t('checkout.empty'));

            return Response::redirect('/cart');
        }

        $site = Site::get(fresh: true);

        return View::render('checkout/form', [
            'title' => t('checkout.title'),
            'items' => $cart->items(),
            'errors' => Session::get('_errors', []),
            'methods' => (array) $site['payment_methods'],
            'gateways' => (array) $site['gateways'],
        ]);
    }

    public static function submit(Request $request): Response
    {
        $cart = CartController::cart($request);
        $site = Site::get(fresh: true);

        if ($cart->isEmpty()) {
            return Response::redirect('/cart');
        }

        if (! $site['accepting_orders']) {
            Session::flash('error', t('cart.not_accepting'));

            return Response::redirect('/cart');
        }

        $checked = Checkout::validate(
            $request->post,
            (array) $site['payment_methods'],
            array_column((array) $site['gateways'], 'name'),
        );

        if ($checked['errors'] !== []) {
            Session::flashInput($checked['data']);
            Session::put('_errors', $checked['errors']);

            return Response::redirect('/checkout');
        }

        Session::forget('_errors');

        try {
            $checkout = Checkout::create($cart, $checked['data']);
            Checkout::quote($checkout);
        } catch (ErpException $exception) {
            return self::refuse($exception, $checked['data']);
        } catch (\DomainException) {
            return Response::redirect('/cart');
        }

        return Response::redirect('/checkout/'.$checkout['token']);
    }

    /**
     * The review: the ERP's figures, shown once more before anything is placed.
     */
    public static function review(Request $request, string $token): Response
    {
        $checkout = Checkout::find($token);

        if ($checkout === null) {
            return View::render('errors/404', [], true, 404);
        }

        if ($checkout['status'] === Checkout::PLACED && is_string($checkout['placed_order_id'])) {
            return self::toOrder((string) $checkout['placed_order_id']);
        }

        $quote = $checkout['quote_json'];
        $stale = ! is_array($quote)
            || (is_string($quote['valid_until'] ?? null) && strtotime($quote['valid_until']) < time() && ! ($quote['repriced'] ?? false));

        if ($stale) {
            try {
                $quote = Checkout::quote($checkout);
            } catch (ErpException $exception) {
                return self::refuse($exception, []);
            }
        }

        $repriced = (bool) ($quote['repriced'] ?? false);

        $problems = [];

        foreach ((array) $quote['items'] as $line) {
            if (! ($line['in_stock'] ?? true) || ! ($line['reservable'] ?? true)) {
                $problems[] = (string) $line['sku'];
            }
        }

        $cod = $checkout['payment_method'] === 'cod' && ! ($quote['cod_eligible'] ?? true);

        return View::render('checkout/review', [
            'title' => t('review.title'),
            'checkout' => $checkout,
            'quote' => $quote,
            'repriced' => $repriced,
            'stockProblems' => $problems,
            'codBlocked' => $cod,
            'items' => $checkout['items_json'],
        ]);
    }

    public static function place(Request $request, string $token): Response
    {
        $checkout = Checkout::find($token);

        if ($checkout === null) {
            return View::render('errors/404', [], true, 404);
        }

        if ($checkout['status'] === Checkout::PLACED) {
            return self::toOrder((string) $checkout['placed_order_id']);
        }

        try {
            $result = Checkout::place($checkout, Checkout::returnUrl());
        } catch (\DomainException $exception) {
            Session::flash('info', t($exception->getMessage() === 'already_placing' ? 'review.already_placing' : 'review.not_ready'));

            return Response::redirect('/checkout/'.$token);
        } catch (ErpException $exception) {
            return self::refuseAtPlacement($exception, $token);
        }

        // Prices moved: the customer sees the new total and confirms again, on a new key.
        if ($result['outcome'] === 'repriced') {
            Session::flash('warning', t('review.repriced'));

            return Response::redirect('/checkout/'.$token);
        }

        $order = $result['order'];
        $local = Orders::remember($order, $checkout);

        // The basket is spent; this storefront keeps no copy of what was bought.
        $cart = CartController::cart($request);
        $cart->clear();

        $grants = (array) Session::get('orders', []);
        $grants[(string) $local['public_id']] = (string) $local['access_token'];
        Session::put('orders', $grants);

        $redirect = is_string($order['payment']['redirect_url'] ?? null) ? $order['payment']['redirect_url'] : null;

        // Straight to the gateway, but only to a real https address.
        if ($redirect !== null && str_starts_with($redirect, 'https://') && ($order['payment']['method'] ?? '') === 'online') {
            return Response::redirect($redirect);
        }

        return self::toOrder((string) $local['public_id']);
    }

    private static function toOrder(string $publicId): Response
    {
        $order = Orders::find($publicId);

        return Response::redirect('/order/'.rawurlencode($publicId).($order !== null ? '?t='.$order['access_token'] : ''));
    }

    /**
     * A refusal while checking details out: back to the cart or the form, in words.
     *
     * @param  array<string, string>  $data
     */
    private static function refuse(ErpException $exception, array $data): Response
    {
        if ($data !== []) {
            Session::flashInput($data);
        }

        if ($exception->is('invalid_mobile_number')) {
            Session::put('_errors', ['phone' => 'checkout.error.phone']);

            return Response::redirect('/checkout');
        }

        if ($exception->isUnavailable()) {
            return Kernel::unavailable($exception);
        }

        if ($exception->is('payment_method_unavailable')) {
            return self::backToPaymentChoice($exception);
        }

        Session::flash('error', t(self::messageKey($exception)));

        return Response::redirect('/cart');
    }

    /**
     * The ERP no longer offers what the customer chose: forget the stale options
     * and send them back to the form, which now shows what is actually available.
     */
    private static function backToPaymentChoice(ErpException $exception): Response
    {
        Site::get(fresh: true);
        Session::flash('error', t(self::messageKey($exception)));

        return Response::redirect('/checkout');
    }

    private static function refuseAtPlacement(ErpException $exception, string $token): Response
    {
        if ($exception->isUnavailable()) {
            Session::flash('error', t('review.try_again'));

            return Response::redirect('/checkout/'.$token);
        }

        if ($exception->is('invalid_mobile_number')) {
            Session::put('_errors', ['phone' => 'checkout.error.phone']);

            return Response::redirect('/checkout');
        }

        if ($exception->is('payment_method_unavailable')) {
            return self::backToPaymentChoice($exception);
        }

        Session::flash('error', t(self::messageKey($exception)));

        return Response::redirect('/cart');
    }

    /**
     * The customer-facing sentence for an ERP refusal code. The ERP's own
     * message is for logs: it is English and may name internals.
     */
    private static function messageKey(ErpException $exception): string
    {
        return match ($exception->errorCode) {
            'insufficient_stock' => 'error.insufficient_stock',
            'product_unavailable' => 'error.product_unavailable',
            'quantity_not_allowed' => 'error.quantity_not_allowed',
            'website_not_accepting_orders' => 'cart.not_accepting',
            'payment_method_unavailable' => 'error.payment_method_unavailable',
            'cod_not_available', 'cod_limit_exceeded' => 'error.cod_unavailable',
            'coupon_not_applicable' => 'error.coupon',
            'return_url_not_allowed' => 'error.return_url',
            'duplicate_storefront_order' => 'error.duplicate',
            default => 'error.generic',
        };
    }
}
