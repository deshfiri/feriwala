<?php

namespace App\Http\Controllers\Api\Mobile\V1\Wholesale;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Wholesale\Actions\AcceptCartPrices;
use App\Domain\Wholesale\Actions\OpenCart;
use App\Domain\Wholesale\Actions\SetCartLine;
use App\Domain\Wholesale\Data\CartQuote;
use App\Domain\Wholesale\Exceptions\CartRefused;
use App\Domain\Wholesale\Models\CartItem;
use App\Domain\Wholesale\Queries\PriceCart;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Wholesale\Concerns\GuardsWholesaleFacility;
use App\Http\Requests\Mobile\Wholesale\CartLineRequest;
use App\Http\Resources\Mobile\Wholesale\CartResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The wholesale cart (§14, P4-4, P4-5).
 *
 * Self-scoped through the signed-in person (§31.3): a cart is only ever the
 * requester's own, and a line is found inside that cart or not at all — a line
 * from anybody else's cart is a `404`, whatever identifier is sent. Nothing
 * priced by the client is accepted: adding or changing a line takes a product, a
 * variation and a quantity, and every price and stock figure is worked out again
 * on the server on every request.
 */
class WholesaleCartController extends MobileController
{
    use GuardsWholesaleFacility;
    use ResolvesBusinessAccount;

    public function __construct(
        protected OpenCart $carts,
        protected PriceCart $pricing,
        protected SetCartLine $setLine,
        protected ProductEligibility $eligibility,
    ) {}

    /**
     * Show the cart
     *
     * The caller's wholesale cart, priced now. `facility_allowed` is `false` and
     * `data` is `null` when the package does not include wholesale.
     *
     * @response array{facility_allowed: bool, data: CartResource|null}
     */
    public function show(Request $request): JsonResponse
    {
        $account = $this->businessAccountFor($request);
        $allowed = $this->eligibility->allowsChannel($account, SalesChannel::Wholesale);

        return new JsonResponse([
            'facility_allowed' => $allowed,
            'data' => $allowed ? new CartResource($this->quote($request, $account)) : null,
        ]);
    }

    /**
     * Set a cart line
     *
     * Puts a product (and variation, when it has them) in the cart at exactly the
     * given quantity, replacing any earlier quantity. Answers with the re-priced
     * cart. `404` for a product this account may not buy wholesale; `403`
     * `wholesale_not_allowed` when the package excludes wholesale; `422` when the
     * quantity breaks the product's minimum or maximum, the variation is wrong or
     * the stock this account can order is short.
     *
     * @response 201 array{data: CartResource}
     */
    public function store(CartLineRequest $request): JsonResponse
    {
        $account = $this->businessAccountFor($request);
        $this->requireWholesale($account);

        $validated = $request->validated();

        // Only a product this account may buy wholesale is found at all.
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

        $this->set(fn () => $this->setLine->handle($this->person($request), $account, $product, $variant, (int) $validated['quantity']));

        return (new JsonResponse(['data' => new CartResource($this->quote($request, $account))]))->setStatusCode(201);
    }

    /**
     * Change a line's quantity
     *
     * Sets the line to the new quantity and answers with the re-priced cart.
     * A line that is not in the caller's own cart answers `404`.
     *
     * @response array{data: CartResource}
     */
    public function update(CartLineRequest $request, string $item): JsonResponse
    {
        $account = $this->businessAccountFor($request);
        $this->requireWholesale($account);
        $line = $this->ownLine($request, $account, $item);

        $this->set(fn () => $this->setLine->handle(
            $this->person($request),
            $account,
            $line->product,
            $line->variant,
            (int) $request->validated('quantity'),
        ));

        return new JsonResponse(['data' => new CartResource($this->quote($request, $account))]);
    }

    /**
     * Remove a line
     *
     * Takes the line out of the caller's own cart. `404` for any other line.
     */
    public function destroy(Request $request, string $item): Response
    {
        $account = $this->businessAccountFor($request);

        $this->ownLine($request, $account, $item)->delete();

        return response()->noContent();
    }

    /**
     * Accept the new prices
     *
     * Marks every price that moved since the person last looked as seen, so the
     * cart can go on to checkout. Answers with the re-priced cart.
     *
     * @response array{data: CartResource}
     */
    public function acceptPrices(Request $request, AcceptCartPrices $accept): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        $accept->handle($this->person($request), $account);

        return new JsonResponse(['data' => new CartResource($this->quote($request, $account))]);
    }

    protected function quote(Request $request, BusinessAccount $account): CartQuote
    {
        return $this->pricing->quote($this->carts->find($this->person($request), $account), $account);
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
    protected function set(callable $change): void
    {
        try {
            $change();
        } catch (CartRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }
    }
}
