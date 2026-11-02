<?php

namespace App\Http\Controllers\Api\Mobile\V1\Catalog;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Website\Actions\SetPurchasedProductOnWebsite;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Queries\WebsiteOverview;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Catalog\MyProductWebsiteRequest;
use App\Http\Resources\Mobile\MoneyResource;
use App\Models\User;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\Money\DecimalAmount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Putting a bought product on a storefront, from My Products (D34).
 *
 * One storefront at a time: the partner's own, found inside their account, and a
 * product only if it is one they may sell. Anything else is a `404` rather than
 * a refusal that confirms it exists.
 */
class MyProductWebsiteController extends MobileController
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected WebsiteOverview $overview,
        protected ProductEligibility $eligibility,
    ) {}

    /**
     * Publish or unpublish on a storefront
     *
     * Puts a product the account bought on one of its own storefronts (`published: true`,
     * with an optional selling `price` that is checked against the administrator's
     * range), or takes it off (`published: false`; the selection and its price stay).
     * `403` when the caller may not manage that storefront; `404` for a storefront
     * or product that is not the account's to use; `422` when the platform refuses
     * (price outside the range, nothing left to sell, package limit, KYC) or the
     * storefront is busy.
     *
     * @response array{data: array{product: string, website: string, published: bool, price: MoneyResource|null}}
     */
    public function update(MyProductWebsiteRequest $request, string $product, string $website, SetPurchasedProductOnWebsite $set): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        $record = $this->overview->findForAccount($account, $website);

        abort_if($record === null, 404);

        Gate::authorize('manage', $record);

        /** @var Product|null $found */
        $found = $this->eligibility
            ->query($account, SalesChannel::Dropshipping)
            ->where('slug', $product)
            ->first();

        abort_if($found === null, 404);

        $validated = $request->validated();
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        $published = (bool) $validated['published'];

        $price = $published
            ? DecimalAmount::parseOrNull($validated['price'] ?? null)?->toDecimal()
            : null;

        $selection = $this->attempt($record, fn () => $set->handle($record, $found, $actor, $published, $price));

        return new JsonResponse(['data' => [
            'product' => $found->slug,
            'website' => $record->public_id,
            'published' => $published,
            'price' => MoneyResource::nullable($selection?->price),
        ]]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    protected function attempt(Website $website, callable $step): mixed
    {
        try {
            return $step();
        } catch (WebsiteRefused $refused) {
            throw ValidationException::withMessages(['website' => $website->name.': '.$refused->getMessage()]);
        } catch (LockTimeout) {
            throw ValidationException::withMessages(['website' => __('website.refused.busy')]);
        }
    }
}
