<?php

namespace App\Http\Controllers\Api\Storefront\V1;

use App\Domain\Website\Api\StorefrontError;
use App\Domain\Website\Api\StorefrontProductPayload;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Website\Queries\StorefrontProductListing;
use App\Domain\Website\Queries\StorefrontSelections;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * A storefront reading its own catalogue (contract §5.1, P5-22).
 *
 * **Only this website's published selections**, found through the website the
 * credential belongs to. A product another website sells, or one this website
 * selected but never published, is a `404` — never a `403` that would confirm
 * it exists somewhere (contract §4.5).
 *
 * `updated_since` is what makes scheduled reconciliation cheap: a storefront
 * asks only for what changed, whether the partner changed the price or
 * Feriwala changed the product.
 *
 * @phpstan-import-type Filters from StorefrontProductListing
 */
class ProductController extends StorefrontController
{
    /** What a payload reads, loaded with the page. */
    protected const RELATIONS = [
        'product.brand',
        'product.category',
        'product.media',
        'product.variants.values.attribute',
        'websiteCategory',
    ];

    public function __construct(
        protected StorefrontProductPayload $payload,
        protected StorefrontSelections $selections,
        protected StorefrontProductListing $listing,
    ) {}

    /**
     * The catalogue, searched, filtered and sorted (contract §5.1, P2 C1).
     *
     * With no `sort` the order is the selection id, exactly as before, so a
     * cursor issued before these parameters existed still pages correctly.
     */
    public function index(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $started = CarbonImmutable::now();

        $filters = $this->filters($request);

        if ($filters instanceof JsonResponse) {
            return $filters;
        }

        $sort = $request->query('sort');

        if ($sort !== null && (! is_string($sort) || ! in_array($sort, StorefrontProductListing::SORTS, true))) {
            return StorefrontError::respond($request, 422, 'validation_failed', 'sort is not one this API offers.', [
                'field' => 'sort',
                'allowed' => StorefrontProductListing::SORTS,
            ]);
        }

        $query = $this->listing->filtered($website, $filters, $started);

        $page = ($sort === null ? $query->orderBy('website_products.id') : $this->listing->sorted($query, $sort, $started))
            ->with(self::RELATIONS)
            ->cursorPaginate($this->limit($request));

        $items = collect($page->items());

        $response = new JsonResponse($this->envelope(
            $page,
            $items->map(fn (WebsiteProduct $selection) => $this->payload->for($selection, $website))->all(),
        ));

        $this->noteRead($website, $items->pluck('id')->all(), $started);

        return $response;
    }

    /**
     * What the catalogue can be filtered by: brands, variation attributes and
     * values, and the price range, under the same filters as the list (C2).
     */
    public function facets(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $filters = $this->filters($request);

        if ($filters instanceof JsonResponse) {
            return $filters;
        }

        $at = CarbonImmutable::now();
        $facets = $this->listing->facets($this->listing->filtered($website, $filters, $at), $at);

        return new JsonResponse($this->withLegacyMoney([
            'data' => [
                'brands' => $facets['brands'],
                'attributes' => $facets['attributes'],
                'price_range' => $facets['price_range'] === null ? null : [
                    'minimum' => $facets['price_range']['minimum']->jsonSerialize(),
                    'maximum' => $facets['price_range']['maximum']->jsonSerialize(),
                ],
            ],
        ]));
    }

    public function show(Request $request, string $product): JsonResponse
    {
        $website = $this->website($request);

        /** @var WebsiteProduct|null $selection */
        $selection = $this->published($website)
            ->whereHas('product', fn (Builder $query) => $query
                ->where('public_id', $product)
                ->orWhere('slug', $product))
            ->first();

        if ($selection === null) {
            return StorefrontError::respond($request, 404, 'not_found', 'No such product on this website.');
        }

        $started = CarbonImmutable::now();
        $response = new JsonResponse($this->payload->for($selection, $website));

        $this->noteRead($website, [$selection->id], $started);

        return $response;
    }

    /**
     * The storefront has now read these, so its copy is current (§16.2, P5-7, P5-28).
     *
     * The pull half of synchronisation: a storefront with no webhook endpoint
     * keeps itself current by reading, and the partner's screen should say so.
     * Only a selection that has not changed since this request began is marked,
     * so an edit made while the response was being built stays pending.
     *
     * @param  array<int, int>  $ids
     */
    protected function noteRead(Website $website, array $ids, CarbonImmutable $started): void
    {
        if ($ids === []) {
            return;
        }

        WebsiteProduct::query()
            ->whereIn('id', $ids)
            ->where('updated_at', '<=', $started)
            ->where('sync_status', '!=', WebsiteSyncStatus::Synced->value)
            // Not an edit: `updated_at` stays what `updated_since` compares.
            ->toBase()
            ->update([
                'sync_status' => WebsiteSyncStatus::Synced->value,
                'last_synced_at' => $started,
                'sync_error' => null,
            ]);

        Website::query()->whereKey($website->id)->update(['last_synced_at' => $started]);
    }

    /**
     * This website's selections that are on sale, and only its: published, and
     * eligible for its owner to sell (P2 H9).
     *
     * @return Builder<WebsiteProduct>
     */
    protected function published(Website $website): Builder
    {
        return $this->selections->query($website)->with(self::RELATIONS);
    }

    /**
     * The list's filters, validated, or the refusal (C1).
     *
     * `status` is the contract's own parameter: only published products are
     * ever on this surface, so `published` is the one value it accepts.
     *
     * @return Filters|JsonResponse
     */
    protected function filters(Request $request): array|JsonResponse
    {
        $flag = ['sometimes', 'nullable', 'string', 'in:true,false,1,0'];

        $validator = Validator::make($request->query(), [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'updated_since' => ['sometimes', 'nullable', 'string', 'max:64'],
            'price_min' => ['sometimes', 'nullable', 'string', new DecimalAmountRule],
            'price_max' => ['sometimes', 'nullable', 'string', new DecimalAmountRule],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'attributes' => ['sometimes', 'array', 'max:'.StorefrontProductListing::MAX_ATTRIBUTE_FILTERS],
            'attributes.*' => ['string', 'max:100'],
            'in_stock' => $flag,
            'featured' => $flag,
            'flash_deal' => $flag,
            'status' => ['sometimes', 'nullable', 'string', 'in:published'],
        ]);

        if ($validator->fails()) {
            return StorefrontError::respond($request, 422, 'validation_failed', 'The product filters are not valid.', [
                'fields' => $validator->errors()->toArray(),
            ]);
        }

        /** @var array<string, mixed> $input */
        $input = $validator->validated();
        $filters = [];

        $text = fn (string $key) => isset($input[$key]) && is_string($input[$key]) && trim($input[$key]) !== ''
            ? trim($input[$key])
            : null;

        if (($since = $text('updated_since')) !== null) {
            try {
                $filters['updated_since'] = CarbonImmutable::parse($since);
            } catch (Throwable) {
                return StorefrontError::respond($request, 422, 'validation_failed', 'updated_since is not a date.', [
                    'field' => 'updated_since',
                ]);
            }
        }

        foreach (['q', 'category', 'brand'] as $key) {
            if (($value = $text($key)) !== null) {
                $filters[$key] = $value;
            }
        }

        foreach (['price_min', 'price_max'] as $key) {
            if (($value = $text($key)) !== null) {
                $filters[$key] = DecimalAmount::parse($value, Currency::base());
            }
        }

        if (isset($filters['price_min'], $filters['price_max']) && $filters['price_min']->greaterThan($filters['price_max'])) {
            return StorefrontError::respond($request, 422, 'validation_failed', 'price_min is above price_max.', [
                'field' => 'price_min',
            ]);
        }

        $attributes = [];

        foreach ($input['attributes'] ?? [] as $attribute => $value) {
            if (! is_string($attribute) || strlen($attribute) > 64 || ! is_string($value) || trim($value) === '') {
                return StorefrontError::respond($request, 422, 'validation_failed', 'attributes takes an attribute slug and a value.', [
                    'field' => 'attributes',
                ]);
            }

            $attributes[$attribute] = trim($value);
        }

        if ($attributes !== []) {
            $filters['attributes'] = $attributes;
        }

        foreach (['in_stock', 'featured', 'flash_deal'] as $key) {
            if (($value = $text($key)) !== null) {
                $filters[$key] = in_array($value, ['true', '1'], true);
            }
        }

        return $filters;
    }
}
