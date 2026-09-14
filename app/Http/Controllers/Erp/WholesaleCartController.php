<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Wholesale\Actions\AcceptCartPrices;
use App\Domain\Wholesale\Actions\OpenCart;
use App\Domain\Wholesale\Actions\SetCartLine;
use App\Domain\Wholesale\Data\CartLineQuote;
use App\Domain\Wholesale\Exceptions\CartRefused;
use App\Domain\Wholesale\Models\CartItem;
use App\Domain\Wholesale\Queries\PriceCart;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The ERP wholesale cart (§14, P4-4, P4-5).
 *
 * **Self-scoped through the signed-in person** (§31.3): a cart is only ever the
 * requester's own, and a line is found inside that cart or not at all — a line
 * from anybody else's cart is a 404, whatever identifier is sent.
 *
 * **Nothing priced by the browser is accepted.** Adding or changing a line takes
 * a product, a variation and a quantity; every price, total, stock figure and
 * quantity rule on the page comes back from the server, worked out again on every
 * request.
 */
class WholesaleCartController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected OpenCart $carts,
        protected PriceCart $pricing,
        protected SetCartLine $setLine,
        protected ProductEligibility $eligibility,
        protected ProductMediaStore $media,
    ) {}

    public function show(Request $request): Response
    {
        $account = $this->businessAccountFor($request);
        $allowed = $this->eligibility->allowsChannel($account, SalesChannel::Wholesale);
        $quote = $allowed
            ? $this->pricing->quote($this->carts->find($this->person($request), $account), $account)
            : null;

        return Inertia::render('wholesale/cart', [
            'facility_allowed' => $allowed,
            'cart' => $quote === null ? null : [
                'lines' => array_map(fn (CartLineQuote $line) => $this->line($line), $quote->lines),
                'subtotal' => $quote->subtotal->jsonSerialize(),
                'line_count' => count($quote->lines),
                'has_problems' => $quote->hasProblems(),
                'has_price_changes' => $quote->hasPriceChanges(),
                'ready_for_checkout' => $quote->isReadyForCheckout(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        $validated = $request->validate([
            'product' => ['required', 'string', 'max:255'],
            'variant' => ['nullable', 'string', 'max:64'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        // Only a product this account may buy wholesale is found at all; anything
        // else is a 404, as it is on the catalogue.
        $product = $this->eligibility->query($account, SalesChannel::Wholesale)
            ->where('slug', $validated['product'])
            ->first();

        abort_if($product === null, 404);

        $variant = filled($validated['variant'] ?? null)
            ? ProductVariant::query()->where('product_id', $product->id)->where('sku', $validated['variant'])->first()
            : null;

        if (filled($validated['variant'] ?? null) && $variant === null) {
            throw ValidationException::withMessages(['variant' => CartRefused::variationUnavailable()->getMessage()]);
        }

        $this->set($request, $account, fn () => $this->setLine->handle($this->person($request), $account, $product, $variant, (int) $validated['quantity']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('wholesale.cart.added', ['name' => $product->name])]);

        return back();
    }

    public function update(Request $request, string $item): RedirectResponse
    {
        $account = $this->businessAccountFor($request);
        $line = $this->ownLine($request, $account, $item);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $this->set($request, $account, fn () => $this->setLine->handle(
            $this->person($request),
            $account,
            $line->product,
            $line->variant,
            (int) $validated['quantity'],
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('wholesale.cart.updated')]);

        return back();
    }

    public function destroy(Request $request, string $item): RedirectResponse
    {
        $account = $this->businessAccountFor($request);
        $line = $this->ownLine($request, $account, $item);
        $name = $line->product->name;

        $line->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('wholesale.cart.removed', ['name' => $name])]);

        return back();
    }

    public function acceptPrices(Request $request, AcceptCartPrices $accept): RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        $accept->handle($this->person($request), $account);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('wholesale.cart.prices_accepted')]);

        return back();
    }

    /**
     * A line in the requester's own cart, or a 404.
     */
    protected function ownLine(Request $request, BusinessAccount $account, string $publicId): CartItem
    {
        $cart = $this->carts->find($this->person($request), $account);

        abort_if($cart === null, 404);

        /** @var CartItem $line */
        $line = CartItem::query()
            ->with(['product', 'variant'])
            ->where('cart_id', $cart->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return $line;
    }

    /**
     * @param  callable(): CartItem  $change
     */
    protected function set(Request $request, BusinessAccount $account, callable $change): void
    {
        abort_unless($this->eligibility->allowsChannel($account, SalesChannel::Wholesale), 403);

        try {
            $change();
        } catch (CartRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(CartLineQuote $line): array
    {
        $item = $line->item;
        $product = $item->product;
        $variant = $item->variant;
        $image = $product->media()->where('type', ProductMedia::TYPE_IMAGE)->first();

        return [
            'id' => $item->public_id,
            'product' => [
                'slug' => $product->slug,
                'name' => $product->name,
                'sku' => $product->sku,
                'image' => $image === null ? null : ['url' => $this->media->url($image->path), 'alt' => $image->alt_text],
            ],
            'variant' => $variant === null ? null : [
                'sku' => $variant->sku,
                'label' => $variant->values
                    ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                    ->pluck('value')
                    ->implode(' / '),
            ],
            'quantity' => $item->quantity,
            'min_order_quantity' => $product->min_order_quantity,
            'max_order_quantity' => $product->max_order_quantity,
            'available' => $line->available,
            'unit_price' => $line->unitPrice?->jsonSerialize(),
            'base_price' => $line->basePrice?->jsonSerialize(),
            'line_total' => $line->lineTotal?->jsonSerialize(),
            'unit_price_seen' => $line->unitPriceSeen?->jsonSerialize(),
            'price_changed' => $line->priceChanged,
            'problems' => $line->problems,
            'purchasable' => $line->isPurchasable(),
        ];
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
