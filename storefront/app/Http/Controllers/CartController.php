<?php

namespace App\Http\Controllers;

use App\Erp\ErpException;
use App\Services\Basket;
use App\Services\BasketQuote;
use App\Services\Catalog;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class CartController extends Controller
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly BasketQuote $quotes,
    ) {}

    public static function basket(Request $request): Basket
    {
        return Basket::forToken($request->cookie(Basket::COOKIE));
    }

    public function show(Request $request): View
    {
        $items = self::basket($request)->items();
        $quote = null;
        $problem = null;

        if ($items !== []) {
            try {
                $quote = $this->quotes->for($items);
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

        return view('cart.show', [
            'title' => t('cart.title'),
            'items' => $items,
            'quote' => $quote,
            'prices' => $prices,
            'lineProblems' => $problems,
            'problem' => $problem,
        ]);
    }

    public function add(Request $request): RedirectResponse
    {
        $slug = request_string($request, 'product');
        $sku = Basket::sku(request_string($request, 'sku'));
        $quantity = (int) request_string($request, 'quantity', '1');
        $back = route('products.show', ['slug' => $slug]);

        // Read from the ERP, not from the form: a customer's browser says which
        // line they want, and the ERP says what that line is.
        $product = $slug === '' ? null : $this->catalog->product($slug, fresh: true);

        if ($product === null) {
            flash_message('error', t('cart.not_found'));

            return redirect()->route('products.index');
        }

        $variant = null;

        foreach ((array) ($product['variants'] ?? []) as $candidate) {
            if (is_array($candidate) && Basket::sku((string) ($candidate['sku'] ?? '')) === $sku) {
                $variant = $candidate;
            }
        }

        if ($variant === null) {
            flash_message('error', t('cart.choose_option'));

            return redirect($back);
        }

        if (! ($variant['availability']['in_stock'] ?? false)) {
            flash_message('error', t('cart.out_of_stock'));

            return redirect($back);
        }

        $basket = self::basket($request);
        $min = max(1, (int) ($product['min_order_quantity'] ?? 1));
        $max = (int) ($product['max_order_quantity'] ?? 0);

        $already = 0;

        foreach ($basket->items() as $line) {
            if ($line['sku'] === $sku) {
                $already = $line['quantity'];
            }
        }

        $quantity = max($min, $quantity);

        if ($max > 0 && $already + $quantity > $max) {
            $quantity = max(0, $max - $already);

            if ($quantity === 0) {
                flash_message('error', t('cart.max_reached', ['max' => (string) $max]));

                return redirect($back);
            }

            flash_message('info', t('cart.max_limited', ['max' => (string) $max]));
        }

        $available = (int) ($variant['availability']['quantity'] ?? 0);

        if (! ($variant['availability']['backorderable'] ?? false) && $available > 0 && $already + $quantity > $available) {
            $quantity = max(0, $available - $already);

            if ($quantity === 0) {
                flash_message('error', t('cart.no_more', ['n' => (string) $available]));

                return redirect($back);
            }

            flash_message('info', t('cart.stock_limited', ['n' => (string) $available]));
        }

        try {
            $basket->add($sku, $quantity, [
                'product_id' => (string) $product['id'],
                'slug' => (string) $product['slug'],
                'name' => (string) $product['name'],
                'variant' => self::variantLabel($variant),
                'image' => Catalog::primaryImage($product),
            ]);
        } catch (DomainException) {
            flash_message('error', t('cart.full'));

            return redirect()->route('cart.show');
        }

        self::remember($basket, $request);
        flash_message('success', t('cart.added', ['name' => (string) $product['name']]));

        return redirect()->route('cart.show');
    }

    public function update(Request $request): RedirectResponse
    {
        self::basket($request)->setQuantity(request_string($request, 'sku'), (int) request_string($request, 'quantity', '1'));

        return redirect()->route('cart.show');
    }

    public function remove(Request $request): RedirectResponse
    {
        self::basket($request)->remove(request_string($request, 'sku'));

        return redirect()->route('cart.show');
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
     * Put the basket's token in the visitor's cookie for thirty days.
     */
    public static function remember(Basket $basket, Request $request): void
    {
        Cookie::queue(Cookie::make(Basket::COOKIE, $basket->ensureToken(), 60 * 24 * 30, '/', null, $request->isSecure(), true, false, 'lax'));
    }
}
