<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ManageVariants;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveVariantRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * A product's variations (§11.1, §12).
 *
 * §12 names "unauthorized product variations" explicitly, so creating one asks
 * the catalogue's create permission — the same one a product needs — and a
 * variant is always addressed through the product it belongs to, so a variant id
 * from one product cannot be used to edit another's.
 */
class ProductVariantController extends Controller
{
    public function __construct(
        protected ManageVariants $variants,
    ) {}

    public function store(SaveVariantRequest $request, string $product): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canCreate($actor), 403);

        try {
            $variant = $this->variants->create($actor, $this->product($product), $request->validated());
        } catch (CatalogRefused $refused) {
            throw ValidationException::withMessages(['values' => $refused->getMessage()]);
        }

        return back()->with('success', __('catalog.variants.created', ['sku' => $variant->sku]));
    }

    public function update(SaveVariantRequest $request, string $product, string $variant): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $record = $this->variant($this->product($product), $variant);

        $updated = $this->variants->update($actor, $record, Arr::except($request->validated(), ['values']));

        return back()->with('success', __('catalog.variants.updated', ['sku' => $updated->sku]));
    }

    public function destroy(Request $request, string $product, string $variant): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canDelete($actor), 403);

        try {
            $this->variants->delete($actor, $this->variant($this->product($product), $variant));
        } catch (CatalogRefused $refused) {
            return back()->withErrors(['variant' => $refused->getMessage()]);
        }

        return back()->with('success', __('catalog.variants.deleted'));
    }

    protected function product(string $publicId): Product
    {
        /** @var Product $product */
        $product = Product::query()->where('public_id', $publicId)->firstOrFail();

        return $product;
    }

    /**
     * Scoped to the product in the URL, so a variant of another product is a
     * 404 rather than an edit.
     */
    protected function variant(Product $product, string $publicId): ProductVariant
    {
        /** @var ProductVariant $variant */
        $variant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return $variant;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
