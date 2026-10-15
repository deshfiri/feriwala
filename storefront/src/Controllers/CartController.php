<?php

declare(strict_types=1);

namespace Storefront\Controllers;

use Storefront\App;
use Storefront\Domain\Cart;
use Storefront\Domain\Catalog;
use Storefront\Domain\Site;
use Storefront\Erp\ErpException;
use Storefront\Http\Request;
use Storefront\Http\Response;
use Storefront\Http\Session;
use Storefront\Http\View;

final class CartController
{
    public static function cart(Request $request): Cart
    {
        return Cart::forToken($request->cookies[Cart::COOKIE] ?? null);
    }

    public static function show(Request $request): Response
    {
        $cart = self::cart($request);
        $items = $cart->items();
        $quote = null;
        $problem = null;

        if ($items !== []) {
            try {
                $quote = self::quote($items);
            } catch (ErpException $exception) {
                $problem = match (true) {
                    $exception->is('website_not_accepting_orders') => 'cart.not_accepting',
                    $exception->is('product_unavailable') => 'cart.unavailable_line',
                    $exception->isUnavailable() => 'cart.quote_unavailable',
                    default => 'cart.quote_failed',
                };
            }
        }

        $prices = [];
        $problems = [];

        foreach ((array) ($quote['items'] ?? []) as $line) {
            $prices[(string) $line['sku']] = $line;

            if (! ($line['in_stock'] ?? true) || ! ($line['reservable'] ?? true)) {
                $problems[(string) $line['sku']] = 'cart.out_of_stock';
            }
        }

        return View::render('cart/show', [
            'title' => t('cart.title'),
            'items' => $items,
            'quote' => $quote,
            'prices' => $prices,
            'lineProblems' => $problems,
            'problem' => $problem,
        ]);
    }

    public static function add(Request $request): Response
    {
        $slug = $request->string('product');
        $sku = Cart::sku($request->string('sku'));
        $quantity = (int) $request->string('quantity', '1');
        $back = '/product/'.rawurlencode($slug);

        // Read from the ERP, not from the form: a customer's browser says which
        // line they want, and the ERP says what that line is.
        $product = $slug === '' ? null : Catalog::product($slug, fresh: true);

        if ($product === null) {
            Session::flash('error', t('cart.not_found'));

            return Response::redirect('/products');
        }

        $variant = null;

        foreach ((array) ($product['variants'] ?? []) as $candidate) {
            if (is_array($candidate) && Cart::sku((string) ($candidate['sku'] ?? '')) === $sku) {
                $variant = $candidate;
            }
        }

        if ($variant === null) {
            Session::flash('error', t('cart.choose_option'));

            return Response::redirect($back);
        }

        if (! ($variant['availability']['in_stock'] ?? false)) {
            Session::flash('error', t('cart.out_of_stock'));

            return Response::redirect($back);
        }

        $cart = self::cart($request);
        $min = max(1, (int) ($product['min_order_quantity'] ?? 1));
        $max = (int) ($product['max_order_quantity'] ?? 0);

        $already = 0;

        foreach ($cart->items() as $line) {
            if ($line['sku'] === $sku) {
                $already = $line['quantity'];
            }
        }

        $quantity = max($min, $quantity);

        if ($max > 0 && $already + $quantity > $max) {
            $quantity = max(0, $max - $already);

            if ($quantity === 0) {
                Session::flash('error', t('cart.max_reached', ['max' => (string) $max]));

                return Response::redirect($back);
            }

            Session::flash('info', t('cart.max_limited', ['max' => (string) $max]));
        }

        $available = (int) ($variant['availability']['quantity'] ?? 0);

        if (! ($variant['availability']['backorderable'] ?? false) && $available > 0 && $already + $quantity > $available) {
            $quantity = max(0, $available - $already);

            if ($quantity === 0) {
                Session::flash('error', t('cart.no_more', ['n' => (string) $available]));

                return Response::redirect($back);
            }

            Session::flash('info', t('cart.stock_limited', ['n' => (string) $available]));
        }

        try {
            $cart->add($sku, $quantity, [
                'product_id' => (string) $product['id'],
                'slug' => (string) $product['slug'],
                'name' => (string) $product['name'],
                'variant' => self::variantLabel($variant),
                'image' => Catalog::primaryImage($product),
            ]);
        } catch (\DomainException) {
            Session::flash('error', t('cart.full'));

            return Response::redirect('/cart');
        }

        self::remember($cart);
        Session::flash('success', t('cart.added', ['name' => (string) $product['name']]));

        return Response::redirect('/cart');
    }

    public static function update(Request $request): Response
    {
        $cart = self::cart($request);
        $cart->setQuantity($request->string('sku'), (int) $request->string('quantity', '1'));

        return Response::redirect('/cart');
    }

    public static function remove(Request $request): Response
    {
        self::cart($request)->remove($request->string('sku'));

        return Response::redirect('/cart');
    }

    /**
     * Price a basket. The ERP is asked for a payment method it will take, so the
     * quote answers about the cart and not about a method nobody chose.
     *
     * @param  list<array{sku: string, quantity: int}>  $items
     * @return array<string, mixed>
     *
     * @throws ErpException
     */
    public static function quote(array $items): array
    {
        $methods = (array) Site::get()['payment_methods'];
        $response = App::erp()->post('/checkout/quote', [
            'items' => array_map(static fn (array $line): array => ['sku' => $line['sku'], 'quantity' => $line['quantity']], $items),
            'payment_method' => (string) ($methods[0] ?? 'online'),
        ]);

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $lines = [];

        foreach ((array) ($data['items'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $availability = is_array($line['availability'] ?? null) ? $line['availability'] : [];
            $lines[] = [
                'sku' => (string) ($line['sku'] ?? ''),
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unit_price' => is_array($line['unit_price'] ?? null) ? $line['unit_price'] : null,
                'line_total' => is_array($line['line_total'] ?? null) ? $line['line_total'] : null,
                'in_stock' => (bool) ($availability['in_stock'] ?? true),
                'reservable' => (bool) ($availability['reservable'] ?? true),
            ];
        }

        return [
            'items' => $lines,
            'subtotal' => is_array($data['subtotal'] ?? null) ? $data['subtotal'] : null,
            'discount' => is_array($data['discount'] ?? null) ? $data['discount'] : null,
            'shipping' => is_array($data['shipping'] ?? null) ? $data['shipping'] : null,
            'tax' => is_array($data['tax'] ?? null) ? $data['tax'] : null,
            'grand_total' => is_array($data['grand_total'] ?? null) ? $data['grand_total'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $variant
     */
    public static function variantLabel(array $variant): ?string
    {
        $attributes = is_array($variant['attributes'] ?? null) ? $variant['attributes'] : [];
        $values = array_values(array_filter(array_map(static fn ($v) => is_scalar($v) ? trim((string) $v) : '', $attributes)));

        return $values === [] ? null : implode(' / ', $values);
    }

    /**
     * Put the cart's token in the visitor's cookie.
     */
    public static function remember(Cart $cart): void
    {
        $token = $cart->ensureToken();

        setcookie(Cart::COOKIE, $token, [
            'expires' => time() + 60 * 60 * 24 * 30,
            'path' => '/',
            'secure' => ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
