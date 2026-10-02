<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\DeleteProductContent;
use App\Domain\Catalog\Actions\PublishProductContent;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductContent;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Storage\Exceptions\UnacceptableFile;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Publishing and removing a product's updates (new feature: per-product
 * announcements). Always addressed through the product it belongs to, and
 * gated by the same catalogue edit permission every other product-authoring
 * endpoint already uses (§12) — content is editing the product, not a
 * separate privilege.
 */
class ProductContentController extends Controller
{
    public function __construct(
        protected PublishProductContent $publish,
        protected DeleteProductContent $remove,
    ) {}

    public function store(Request $request, string $product): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $record = $this->product($product);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'image' => [
                'nullable', 'file', 'prohibits:file',
                'mimetypes:'.implode(',', PublishProductContent::IMAGE_TYPES),
                'max:'.intdiv(PublishProductContent::IMAGE_MAX_BYTES, 1024),
            ],
            'file' => [
                'nullable', 'file', 'prohibits:image',
                'mimetypes:'.implode(',', PublishProductContent::FILE_TYPES),
                'max:'.intdiv(PublishProductContent::FILE_MAX_BYTES, 1024),
            ],
        ]);

        /** @var UploadedFile|null $image */
        $image = $request->file('image');

        /** @var UploadedFile|null $file */
        $file = $request->file('file');

        try {
            $this->publish->handle($actor, $record, $validated['title'], $validated['body'] ?? null, $image, $file);
        } catch (UnacceptableFile $refused) {
            throw ValidationException::withMessages([
                $image !== null ? 'image' : 'file' => $refused->getMessage(),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.content.published')]);

        return back();
    }

    public function destroy(Request $request, string $product, string $content): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $this->remove->handle($actor, $this->contentOf($this->product($product), $content));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.content.removed')]);

        return back();
    }

    protected function product(string $publicId): Product
    {
        /** @var Product $product */
        $product = Product::query()->where('public_id', $publicId)->firstOrFail();

        return $product;
    }

    /**
     * Scoped to the product in the URL, so another product's content is a 404.
     */
    protected function contentOf(Product $product, string $publicId): ProductContent
    {
        /** @var ProductContent $content */
        $content = ProductContent::query()
            ->where('product_id', $product->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return $content;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
