<?php

namespace App\Http\Controllers;

use App\Erp\ErpException;
use App\Http\Requests\CheckoutDetailsRequest;
use App\Models\Checkout;
use App\Services\BasketQuote;
use App\Services\Checkouts;
use App\Services\Orders;
use App\Services\Site;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly Checkouts $checkouts,
        private readonly Orders $orders,
        private readonly Site $site,
        private readonly BasketQuote $quotes,
    ) {}

    public function form(Request $request): View|RedirectResponse
    {
        $basket = CartController::basket($request);

        if ($basket->isEmpty()) {
            flash_message('info', t('checkout.empty'));

            return redirect()->route('cart.show');
        }

        $site = $this->site->get(fresh: true);
        $items = $basket->items();

        // The same ERP figures the cart shows. If the ERP cannot answer, the form
        // still works and the total is confirmed on the review step.
        try {
            $quote = $this->quotes->for($items);
        } catch (ErpException $exception) {
            if (! $exception->isUnavailable() && ! $exception->is('product_unavailable') && ! $exception->is('website_not_accepting_orders')) {
                report($exception);
            }

            $quote = null;
        }

        $prices = [];

        foreach ((array) ($quote['items'] ?? []) as $line) {
            $prices[(string) $line['sku']] = $line;
        }

        return view('checkout.form', [
            'title' => t('checkout.title'),
            'items' => $items,
            'quote' => $quote,
            'prices' => $prices,
            'methods' => (array) $site['payment_methods'],
            'gateways' => (array) $site['gateways'],
        ]);
    }

    public function submit(CheckoutDetailsRequest $request): RedirectResponse|Response
    {
        $basket = CartController::basket($request);

        if ($basket->isEmpty()) {
            return redirect()->route('cart.show');
        }

        if (! $this->site->get()['accepting_orders']) {
            flash_message('error', t('cart.not_accepting'));

            return redirect()->route('cart.show');
        }

        $details = $request->details();

        try {
            $checkout = $this->checkouts->create($basket, $details);
            $this->checkouts->quote($checkout);
        } catch (ErpException $exception) {
            return $this->refuse($exception, $details);
        } catch (DomainException) {
            return redirect()->route('cart.show');
        }

        return redirect()->route('checkout.review', ['token' => $checkout->token]);
    }

    /**
     * The review: the ERP's figures, shown once more before anything is placed.
     */
    public function review(string $token): View|RedirectResponse
    {
        $checkout = $this->checkouts->find($token);

        abort_if($checkout === null, 404);

        if ($checkout->status === Checkout::PLACED && is_string($checkout->placed_order_id)) {
            return $this->toOrder($checkout->placed_order_id);
        }

        $quote = $checkout->quote_json;
        $stale = ! is_array($quote)
            || (is_string($quote['valid_until'] ?? null) && strtotime($quote['valid_until']) < time() && ! ($quote['repriced'] ?? false));

        if ($stale) {
            try {
                $quote = $this->checkouts->quote($checkout);
            } catch (ErpException $exception) {
                return $this->refuse($exception, []);
            }
        }

        $problems = [];

        foreach ((array) $quote['items'] as $line) {
            if (! ($line['in_stock'] ?? true) || ! ($line['reservable'] ?? true)) {
                $problems[] = (string) $line['sku'];
            }
        }

        return view('checkout.review', [
            'title' => t('review.title'),
            'checkout' => $checkout,
            'quote' => $quote,
            'repriced' => (bool) ($quote['repriced'] ?? false),
            'stockProblems' => $problems,
            'codBlocked' => $checkout->payment_method === 'cod' && ! ($quote['cod_eligible'] ?? true),
            'items' => $checkout->items_json,
        ]);
    }

    public function place(Request $request, string $token): RedirectResponse
    {
        $checkout = $this->checkouts->find($token);

        abort_if($checkout === null, 404);

        if ($checkout->status === Checkout::PLACED) {
            return $this->toOrder((string) $checkout->placed_order_id);
        }

        try {
            $result = $this->checkouts->place($checkout, Checkouts::returnUrl());
        } catch (DomainException $exception) {
            flash_message('info', t($exception->getMessage() === 'already_placing' ? 'review.already_placing' : 'review.not_ready'));

            return redirect()->route('checkout.review', ['token' => $token]);
        } catch (ErpException $exception) {
            return $this->refuseAtPlacement($exception, $token);
        }

        // Prices moved: the customer sees the new total and confirms again, on a new key.
        if ($result['outcome'] === 'repriced') {
            flash_message('warning', t('review.repriced'));

            return redirect()->route('checkout.review', ['token' => $token]);
        }

        $order = $result['order'];
        $local = $this->orders->remember($order, $checkout);

        // The basket is spent; this storefront keeps no copy of what was bought.
        CartController::basket($request)->clear();

        $grants = (array) session('orders', []);
        $grants[$local->public_id] = $local->access_token;
        session(['orders' => $grants]);

        $redirect = is_string($order['payment']['redirect_url'] ?? null) ? $order['payment']['redirect_url'] : null;

        // Straight to the gateway, but only to a real https address.
        if ($redirect !== null && str_starts_with($redirect, 'https://') && ($order['payment']['method'] ?? '') === 'online') {
            return redirect()->away($redirect);
        }

        return $this->toOrder($local->public_id);
    }

    private function toOrder(string $publicId): RedirectResponse
    {
        $order = $this->orders->find($publicId);

        return redirect()->route('orders.show', ['id' => $publicId] + ($order !== null ? ['t' => $order->access_token] : []));
    }

    /**
     * A refusal while checking details out: back to the cart or the form, in words.
     *
     * @param  array<string, string>  $data
     */
    private function refuse(ErpException $exception, array $data): RedirectResponse|Response
    {
        if ($exception->is('invalid_mobile_number')) {
            return $this->backToForm(['phone' => t('checkout.error.phone')], $data);
        }

        if ($exception->isUnavailable()) {
            throw $exception;
        }

        if ($exception->is('payment_method_unavailable')) {
            return $this->backToPaymentChoice($exception, $data);
        }

        flash_message('error', t(self::messageKey($exception)));

        return redirect()->route('cart.show')->withInput($data);
    }

    /**
     * The ERP no longer offers what the customer chose: forget the stale options
     * and send them back to the form, which now shows what is actually available.
     *
     * @param  array<string, string>  $data
     */
    private function backToPaymentChoice(ErpException $exception, array $data = []): RedirectResponse
    {
        $this->site->get(fresh: true);
        flash_message('error', t(self::messageKey($exception)));

        return redirect()->route('checkout.form')->withInput($data);
    }

    /**
     * @param  array<string, string>  $errors
     * @param  array<string, string>  $data
     */
    private function backToForm(array $errors, array $data): RedirectResponse
    {
        return redirect()->route('checkout.form')->withInput($data)->withErrors($errors);
    }

    private function refuseAtPlacement(ErpException $exception, string $token): RedirectResponse
    {
        if ($exception->isUnavailable()) {
            flash_message('error', t('review.try_again'));

            return redirect()->route('checkout.review', ['token' => $token]);
        }

        if ($exception->is('invalid_mobile_number')) {
            return $this->backToForm(['phone' => t('checkout.error.phone')], []);
        }

        if ($exception->is('payment_method_unavailable')) {
            return $this->backToPaymentChoice($exception);
        }

        flash_message('error', t(self::messageKey($exception)));

        return redirect()->route('cart.show');
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
