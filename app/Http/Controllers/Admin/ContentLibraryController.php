<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\ContentLibrary\Actions\DeleteContentLibraryItem;
use App\Domain\ContentLibrary\Actions\SaveContentLibraryItem;
use App\Domain\ContentLibrary\Actions\UploadContentLibraryFile;
use App\Domain\ContentLibrary\ContentBlocks;
use App\Domain\ContentLibrary\Models\ContentLibraryItem;
use App\Domain\ContentLibrary\Policies\ContentLibraryPolicy;
use App\Domain\Sourcing\Queries\ProductLinkSummaries;
use App\Domain\Storage\Exceptions\UnacceptableFile;
use App\Domain\Storage\ManagedStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\ContentLibrary\SaveContentLibraryItemRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The Content Library: content released to chosen Products from one place.
 *
 * Thin on purpose — every write is delegated to an action, and every action
 * asks {@see ContentLibraryPolicy} again, so this controller is not the only
 * thing standing between a request and the library.
 */
class ContentLibraryController extends Controller
{
    public const PER_PAGE = 20;

    public function __construct(
        protected SaveContentLibraryItem $save,
        protected DeleteContentLibraryItem $remove,
        protected UploadContentLibraryFile $upload,
        protected ContentBlocks $blocks,
        protected ProductLinkSummaries $summaries,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(ContentLibraryPolicy::canView($actor), 403);

        $search = trim($request->string('search')->toString());
        $product = $request->string('product')->toString();

        $items = ContentLibraryItem::query()
            ->with(['creator:id,name', 'products:id,public_id,name'])
            ->withCount('products')
            ->when($search !== '', fn (Builder $query) => $query->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
            ->when($product !== '', fn (Builder $query) => $query->whereHas('products', fn (Builder $inner) => $inner->where('products.public_id', $product)))
            ->orderByDesc('published_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ContentLibraryItem $item) => [
                'id' => $item->public_id,
                'title' => $item->title,
                'block_counts' => collect($item->blocks)->countBy('type')->all(),
                'products_count' => (int) $item->getAttribute('products_count'),
                'products' => $item->products->take(3)->map(fn (Product $related) => ['id' => $related->public_id, 'name' => $related->name])->values()->all(),
                'published_at' => $item->published_at->toIso8601String(),
                'published_by' => $item->creator?->name,
            ]);

        return Inertia::render('admin/content-library/index', [
            'items' => $items,
            'can' => $this->abilities($actor),
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(ContentLibraryPolicy::canPublish($actor), 403);

        return Inertia::render('admin/content-library/form', [
            'item' => null,
            'limits' => $this->limits(),
            'can' => $this->abilities($actor),
        ]);
    }

    public function store(SaveContentLibraryItemRequest $request): RedirectResponse
    {
        $actor = $this->actor($request);

        $item = $this->persist($actor, null, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('content_library.published', ['title' => $item->title])]);

        return to_route('admin.content-library.index');
    }

    public function edit(Request $request, string $item): Response
    {
        $actor = $this->actor($request);

        abort_unless(ContentLibraryPolicy::canView($actor), 403);

        $record = $this->item($item);
        $products = $record->products()->get();
        $summaries = $this->summaries->for($products);

        return Inertia::render('admin/content-library/form', [
            'item' => [
                'id' => $record->public_id,
                'title' => $record->title,
                'blocks' => $this->blocks->forEditor($record->blocks),
                'products' => array_values($summaries),
            ],
            'limits' => $this->limits(),
            'can' => $this->abilities($actor),
        ]);
    }

    public function update(SaveContentLibraryItemRequest $request, string $item): RedirectResponse
    {
        $actor = $this->actor($request);

        $record = $this->persist($actor, $this->item($item), $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('content_library.updated', ['title' => $record->title])]);

        return to_route('admin.content-library.index');
    }

    public function destroy(Request $request, string $item): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(ContentLibraryPolicy::canDelete($actor), 403);

        $this->remove->handle($actor, $this->item($item));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('content_library.removed')]);

        return back();
    }

    /**
     * An image or video chosen in the editor, stored at once so the editor can
     * preview it; it belongs to nothing until a saved item's block names it.
     */
    public function upload(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        abort_unless(ContentLibraryPolicy::canPublish($actor) || ContentLibraryPolicy::canEdit($actor), 403);

        $validated = $request->validate([
            'kind' => ['required', Rule::in(['image', 'video'])],
            'file' => ['required', 'file'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            $stored = $this->upload->handle($actor, $file, $validated['kind']);
        } catch (UnacceptableFile $refused) {
            throw ValidationException::withMessages(['file' => $refused->getMessage()]);
        }

        return response()->json([
            'id' => $stored->public_id,
            'url' => app(ManagedStorage::class)->url($stored),
            'mime_type' => $stored->mime_type,
        ], 201);
    }

    /**
     * Products to release content to: the same BPC, title, SKU and barcode
     * search the Product links use, answered only to someone who may publish.
     */
    public function products(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        abort_unless(ContentLibraryPolicy::canPublish($actor) || ContentLibraryPolicy::canEdit($actor), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'exclude' => ['nullable', 'array', 'max:500'],
            'exclude.*' => ['string', 'max:40'],
        ]);

        return response()->json([
            // Browsable: the picker opens already listing Products, and a
            // search narrows that list.
            'data' => $this->summaries->search((string) ($validated['q'] ?? ''), $validated['exclude'] ?? [], browse: true),
        ]);
    }

    protected function persist(User $actor, ?ContentLibraryItem $item, SaveContentLibraryItemRequest $request): ContentLibraryItem
    {
        $validated = $request->validated();

        try {
            return $this->save->handle($actor, $item, $validated['title'], $validated['blocks'], $validated['product_ids']);
        } catch (InvalidArgumentException $refused) {
            throw ValidationException::withMessages(['blocks' => $refused->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function limits(): array
    {
        return [
            'image_types' => ContentBlocks::IMAGE_TYPES,
            'video_types' => ContentBlocks::VIDEO_TYPES,
            'image_max_mb' => intdiv(ContentBlocks::IMAGE_MAX_BYTES, 1024 * 1024),
            'video_max_mb' => intdiv(ContentBlocks::VIDEO_MAX_BYTES, 1024 * 1024),
            'max_blocks' => ContentBlocks::MAX_BLOCKS,
            'text_max' => ContentBlocks::TEXT_MAX,
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function abilities(User $actor): array
    {
        return [
            'publish' => ContentLibraryPolicy::canPublish($actor),
            'edit' => ContentLibraryPolicy::canEdit($actor),
            'delete' => ContentLibraryPolicy::canDelete($actor),
        ];
    }

    protected function item(string $publicId): ContentLibraryItem
    {
        return ContentLibraryItem::query()->where('public_id', $publicId)->firstOrFail();
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
