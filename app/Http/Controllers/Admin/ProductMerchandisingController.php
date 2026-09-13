<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\SetFeatured;
use App\Domain\Catalog\Actions\SetRelatedProducts;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Related products and featured status (§11.1).
 *
 * Choosing what a product recommends is authoring it — `catalog.edit`.
 * Featuring it is putting it in front of partners first — `catalog.publish`.
 * A business account holds neither and meets a 403 on both.
 */
class ProductMerchandisingController extends Controller
{
    public function __construct(
        protected SetRelatedProducts $related,
        protected SetFeatured $featured,
    ) {}

    public function related(Request $request, string $product): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $record = $this->product($product);

        $validated = $request->validate([
            'related_ids' => ['nullable', 'array', 'max:'.SetRelatedProducts::MAX_RELATED],
            'related_ids.*' => [
                'string', 'distinct',
                Rule::exists(Product::class, 'public_id'),
                Rule::notIn([$record->public_id]),
            ],
        ], [
            'related_ids.*.not_in' => __('catalog.related.not_itself'),
        ]);

        try {
            $this->related->handle($actor, $record, array_values($validated['related_ids'] ?? []));
        } catch (CatalogRefused $refused) {
            throw ValidationException::withMessages(['related_ids' => $refused->getMessage()]);
        }

        return back()->with('success', __('catalog.related.saved'));
    }

    public function featured(Request $request, string $product): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canPublish($actor), 403);

        $validated = $request->validate(['featured' => ['required', 'boolean']]);

        $this->featured->handle($actor, $this->product($product), (bool) $validated['featured']);

        return back()->with('success', __(
            $validated['featured'] ? 'catalog.related.featured_on' : 'catalog.related.featured_off',
        ));
    }

    protected function product(string $publicId): Product
    {
        /** @var Product $product */
        $product = Product::query()->where('public_id', $publicId)->firstOrFail();

        return $product;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
