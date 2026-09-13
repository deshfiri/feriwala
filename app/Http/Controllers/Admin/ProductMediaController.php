<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\ManageProductMedia;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Catalog\ProductMediaStore;
use App\Http\Controllers\Controller;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A product's images and videos (§11.1).
 *
 * Adding a picture is editing the product, so every action asks the catalogue's
 * edit permission — and a business account, holding none, gets a 403 before any
 * byte of its upload is stored (§12). Media is always addressed through the
 * product it belongs to.
 */
class ProductMediaController extends Controller
{
    public function __construct(
        protected ManageProductMedia $media,
    ) {}

    public function store(Request $request, string $product): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $record = $this->product($product);

        $validated = $request->validate([
            /*
             * `mimetypes` reads the file's own bytes. The size depends on which
             * kind of file it turned out to be, so it is checked against that
             * kind's limit — never above what PHP accepts — rather than one
             * figure for both.
             */
            'file' => [
                'required', 'file',
                'mimetypes:'.implode(',', [...ProductMediaStore::IMAGE_TYPES, ...ProductMediaStore::VIDEO_TYPES]),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $type = ProductMediaStore::typeOf((string) $value->getMimeType());

                    if ($type !== null && (int) $value->getSize() > ProductMediaStore::maxBytesFor($type)) {
                        $fail(CatalogRefused::mediaTooLarge($type, (int) $value->getSize(), ProductMediaStore::maxBytesFor($type))->getMessage());
                    }
                },
            ],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'variant_id' => ['nullable', 'string', Rule::exists(ProductVariant::class, 'public_id')],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            $this->media->add($actor, $record, $file, $validated['alt_text'] ?? null, $validated['variant_id'] ?? null);
        } catch (CatalogRefused $refused) {
            throw ValidationException::withMessages([
                str_contains($refused->getMessage(), 'variation') ? 'variant_id' : 'file' => $refused->getMessage(),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.media.uploaded')]);

        return back();
    }

    public function update(Request $request, string $product, string $media): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $record = $this->mediaOf($this->product($product), $media);

        $validated = $request->validate([
            'alt_text' => ['nullable', 'string', 'max:255'],
            'variant_id' => ['nullable', 'string', Rule::exists(ProductVariant::class, 'public_id')],
        ]);

        try {
            $this->media->describe($actor, $record, [
                'alt_text' => $validated['alt_text'] ?? null,
                'variant_id' => $validated['variant_id'] ?? null,
            ]);
        } catch (CatalogRefused $refused) {
            throw ValidationException::withMessages(['variant_id' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.media.updated')]);

        return back();
    }

    public function reorder(Request $request, string $product): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $validated = $request->validate([
            'order' => ['required', 'array', 'max:'.ManageProductMedia::MAX_PER_PRODUCT],
            'order.*' => ['string', 'distinct'],
        ]);

        $this->media->reorder($actor, $this->product($product), array_values($validated['order']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.media.reordered')]);

        return back();
    }

    public function destroy(Request $request, string $product, string $media): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $this->media->remove($actor, $this->mediaOf($this->product($product), $media));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.media.deleted')]);

        return back();
    }

    protected function product(string $publicId): Product
    {
        /** @var Product $product */
        $product = Product::query()->where('public_id', $publicId)->firstOrFail();

        return $product;
    }

    /**
     * Scoped to the product in the URL, so another product's file is a 404.
     */
    protected function mediaOf(Product $product, string $publicId): ProductMedia
    {
        /** @var ProductMedia $media */
        $media = ProductMedia::query()
            ->where('product_id', $product->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return $media;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
