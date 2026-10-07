<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Trash: every product moved out of circulation, reversible until somebody
 * confirms nothing real ever used it (urgent product-management fix).
 *
 * A separate screen from the active catalogue rather than `withTrashed()`
 * shown inline — a trashed product may carry real business history behind it
 * (a status change, a stock movement, a supplier offer), and that is exactly
 * what decides whether Restore or Permanent Delete is the only option offered.
 * Both ask {@see CatalogPolicy::canDelete()}, the same permission trashing a
 * product already asks: putting a product away and taking it back out, or
 * erasing it for good, are all one "may this person touch deletion" question
 * here, not three.
 */
class ProductTrashController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(
        protected ManageProducts $products,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canViewAny($actor), 403);

        $pattern = '%'.addcslashes(trim($request->string('search')->toString()), '%_\\').'%';

        $products = Product::onlyTrashed()
            ->with(['category:id,name', 'brand:id,name', 'deleter:id,name'])
            ->when($request->filled('search'), fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner->where('name', 'ilike', $pattern)->orWhere('sku', 'ilike', $pattern),
            ))
            ->orderByDesc('deleted_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Product $product) => $this->row($product));

        return Inertia::render('admin/catalog/products/trash', [
            'products' => $products,
            'can' => ['delete' => CatalogPolicy::canDelete($actor)],
        ]);
    }

    public function restore(Request $request, string $product): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canDelete($actor), 403);

        try {
            $this->products->restore($actor, $this->product($product));
        } catch (CatalogRefused $refused) {
            return back()->withErrors(['product' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.products.restored')]);

        return back();
    }

    public function destroy(Request $request, string $product): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canDelete($actor), 403);

        try {
            $this->products->permanentlyDelete($actor, $this->product($product));
        } catch (CatalogRefused $refused) {
            return back()->withErrors(['product' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.products.permanently_deleted')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Product $product): array
    {
        return [
            'id' => $product->public_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'category' => $product->category->name,
            'brand' => $product->brand?->name,
            'status' => $product->status->value,
            'status_tone' => $product->status->tone(),
            'deleted_at' => $product->deleted_at?->toIso8601String(),
            'deleted_by' => $product->deleter?->name,
            'deletion_reason' => $product->deletion_reason,
        ];
    }

    protected function product(string $publicId): Product
    {
        /** @var Product $product */
        $product = Product::onlyTrashed()->where('public_id', $publicId)->firstOrFail();

        return $product;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
