<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Actions\ManageProductLinks;
use App\Domain\Sourcing\Exceptions\ProductLinkRefused;
use App\Domain\Sourcing\Models\ProductLink;
use App\Domain\Sourcing\Models\ProductLinkVariantMapping;
use App\Domain\Sourcing\Policies\ProductLinkPolicy;
use App\Domain\Sourcing\Queries\ProductLinkSummaries;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Same Product links, from the Admin Product workspace and the Supplier
 * listing review.
 *
 * Thin on purpose: it identifies who is asking, validates, and hands every
 * decision to {@see ManageProductLinks}. Browser requests carry Product public
 * ids only.
 */
class ProductLinkController extends Controller
{
    public function __construct(
        protected ManageProductLinks $links,
        protected ProductLinkSummaries $summaries,
    ) {}

    /**
     * Suggestions for the "link the same Product" dialogs. Searches BPC, title,
     * SKU and barcode; nothing it returns is preselected or linked.
     */
    public function search(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        abort_unless(ProductLinkPolicy::canView($actor), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'exclude' => ['nullable', 'array', 'max:50'],
            'exclude.*' => ['string', 'max:40'],
        ]);

        return response()->json([
            'data' => $this->summaries->search((string) ($validated['q'] ?? ''), $validated['exclude'] ?? []),
        ]);
    }

    public function store(Request $request, string $product): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(ProductLinkPolicy::canLink($actor), 403);

        $validated = $request->validate([
            'product_id' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $record = $this->product($product);
        $other = Product::query()->where('public_id', $validated['product_id'])->first()
            ?? throw ValidationException::withMessages(['product_id' => __('product_links.errors.not_found')]);

        try {
            $this->links->link($actor, $record, $other, $validated['reason'] ?? null);
        } catch (ProductLinkRefused $refused) {
            throw ValidationException::withMessages(['product_id' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('product_links.linked', ['name' => $other->name])]);

        return back();
    }

    public function destroy(Request $request, string $product, string $link): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(ProductLinkPolicy::canUnlink($actor), 403);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->links->unlink($actor, $this->link($product, $link), $validated['reason'] ?? null);
        } catch (ProductLinkRefused $refused) {
            throw ValidationException::withMessages(['link' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('product_links.unlinked')]);

        return back();
    }

    /**
     * Match a variation of this Product to a variation of the linked one.
     * `variant` is this Product's, `other_variant` the linked Product's; either
     * is left out for a Product that has no variations.
     */
    public function mapVariants(Request $request, string $product, string $link): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(ProductLinkPolicy::canUnlink($actor), 403);

        $validated = $request->validate([
            'variant' => ['nullable', 'string'],
            'other_variant' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $record = $this->product($product);
        $linkRecord = $this->link($product, $link);
        $mineIsA = $linkRecord->product_a_id === $record->id;

        $mine = $this->variant($validated['variant'] ?? null, 'variant');
        $theirs = $this->variant($validated['other_variant'] ?? null, 'other_variant');

        try {
            $this->links->mapVariants(
                $actor,
                $linkRecord,
                $mineIsA ? $mine : $theirs,
                $mineIsA ? $theirs : $mine,
                $validated['reason'] ?? null,
            );
        } catch (ProductLinkRefused $refused) {
            throw ValidationException::withMessages(['variant' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('product_links.variants_matched')]);

        return back();
    }

    public function unmapVariants(Request $request, string $product, string $link, string $mapping): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(ProductLinkPolicy::canUnlink($actor), 403);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $record = ProductLinkVariantMapping::query()
            ->where('public_id', $mapping)
            ->where('product_link_id', $this->link($product, $link)->id)
            ->firstOrFail();

        try {
            $this->links->unmapVariants($actor, $record, $validated['reason'] ?? null);
        } catch (ProductLinkRefused $refused) {
            throw ValidationException::withMessages(['variant' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('product_links.variants_unmatched')]);

        return back();
    }

    protected function product(string $publicId): Product
    {
        return Product::query()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * An active link of this Product, by public id — a link that belongs to
     * other Products is not found, however its id was obtained.
     */
    protected function link(string $product, string $link): ProductLink
    {
        $record = $this->product($product);

        return ProductLink::query()->active()->touching($record->id)->where('public_id', $link)->firstOrFail();
    }

    protected function variant(?string $publicId, string $field): ?ProductVariant
    {
        if (blank($publicId)) {
            return null;
        }

        return ProductVariant::query()->where('public_id', $publicId)->first()
            ?? throw ValidationException::withMessages([$field => __('product_links.errors.variant_not_found')]);
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
