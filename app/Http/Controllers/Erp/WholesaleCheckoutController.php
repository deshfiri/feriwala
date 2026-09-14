<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Wholesale\Actions\ApplyCheckoutCoupon;
use App\Domain\Wholesale\Actions\OpenCart;
use App\Domain\Wholesale\Data\CartLineQuote;
use App\Domain\Wholesale\Exceptions\CheckoutRefused;
use App\Domain\Wholesale\Models\Cart;
use App\Domain\Wholesale\Queries\PriceCheckout;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ERP wholesale checkout (§14).
 *
 * **Self-scoped through the signed-in person**, like the cart it checks out: there
 * is no cart identifier in any URL. **Nothing priced by the browser is used** —
 * the page shows what the server priced on this request, and a coupon is sent as
 * a code and nothing else.
 *
 * A cart that is not ready — a line with a problem, a price not yet accepted,
 * nothing in it — is sent back to the cart, where the reason is shown.
 */
class WholesaleCheckoutController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected OpenCart $carts,
        protected PriceCheckout $checkout,
        protected ProductEligibility $eligibility,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        if (! $this->eligibility->allowsChannel($account, SalesChannel::Wholesale)) {
            return redirect()->route('wholesale.cart.show');
        }

        $cart = $this->carts->find($this->person($request), $account);
        $quote = $this->checkout->quote($cart, $account);

        if ($cart === null || ! $quote->cart->isReadyForCheckout()) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('wholesale.checkout.cart_not_ready')]);

            return redirect()->route('wholesale.cart.show');
        }

        return Inertia::render('wholesale/checkout', [
            'checkout' => [
                'lines' => array_map(fn (CartLineQuote $line) => $this->line($line), $quote->cart->lines),
                'subtotal' => $quote->cart->subtotal->jsonSerialize(),
                'coupon' => $cart->coupon_code === null ? null : [
                    'entered' => $cart->coupon_code,
                    ...($quote->coupon?->toArray() ?? []),
                ],
                'discount' => $quote->discount->jsonSerialize(),
                'total' => $quote->total->jsonSerialize(),
            ],
        ]);
    }

    public function applyCoupon(Request $request, ApplyCheckoutCoupon $apply): RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        abort_unless($this->eligibility->allowsChannel($account, SalesChannel::Wholesale), 403);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        try {
            $outcome = $apply->handle($this->person($request), $account, $validated['code']);
        } catch (CheckoutRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        if (! $outcome->isAccepted) {
            throw ValidationException::withMessages(['code' => $outcome->message()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('wholesale.checkout.coupon_applied', ['code' => $outcome->coupon?->code])]);

        return back();
    }

    public function removeCoupon(Request $request): RedirectResponse
    {
        $account = $this->businessAccountFor($request);
        $cart = $this->carts->find($this->person($request), $account);

        if ($cart !== null) {
            Cart::query()->whereKey($cart->id)->update(['coupon_code' => null]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('wholesale.checkout.coupon_removed')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(CartLineQuote $line): array
    {
        $item = $line->item;
        $variant = $item->variant;

        return [
            'id' => $item->public_id,
            'name' => $item->product->name,
            'sku' => $variant !== null ? $variant->sku : $item->product->sku,
            'variant' => $variant?->values
                ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                ->pluck('value')
                ->implode(' / '),
            'quantity' => $item->quantity,
            'unit_price' => $line->unitPrice?->jsonSerialize(),
            'line_total' => $line->lineTotal?->jsonSerialize(),
        ];
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
