<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Actions\BulkUpdateProducts;
use App\Domain\Catalog\Actions\GenerateVariants;
use App\Domain\Catalog\Actions\ManageProductMedia;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Actions\PublishProductContent;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductContent;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductStatusChange;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Catalog\ProductBarcode;
use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Catalog\ProductSeo;
use App\Domain\Catalog\WholesalePriceResolver;
use App\Domain\Inventory\Actions\SyncProductStockStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Sourcing\Actions\ManageSourcingGroups;
use App\Domain\Sourcing\Exceptions\SourcingGroupRefused;
use App\Domain\Sourcing\Models\ProductSourcingGroup;
use App\Domain\Sourcing\Queries\SourcingGroupOptions;
use App\Domain\Storage\ManagedStorage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveProductRequest;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The central product catalogue, from the administrator's side (§11, §12).
 *
 * Every action asks {@see CatalogPolicy} first. A business account holder holds
 * no platform permission, so each of these is a 403 for a partner — the §12
 * requirement that a regular user cannot create or modify a product is a
 * refusal here, not an absent button.
 *
 * The editor is a page rather than a dialog: a product carries enough fields
 * that a modal would scroll inside a scroll, and the variations, media and
 * pricing that later tasks add all belong beside it.
 */
class ProductController extends Controller
{
    public const PER_PAGE = 25;

    /**
     * Columns the list may sort by. Anything else is ignored rather than passed
     * to `orderBy`, because a sort parameter is somebody's input.
     */
    public const SORTABLE = ['name', 'sku', 'wholesale_price', 'updated_at'];

    public function __construct(
        protected ManageProducts $products,
        protected ProductMediaStore $mediaStore,
        protected WholesalePriceResolver $prices,
        protected ProductSeo $seo,
        protected ManagedStorage $storage,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canViewAny($actor), 403);

        $filters = $this->listFilters($request);
        $pattern = '%'.addcslashes($filters['search'], '%_\\').'%';

        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        $category = $filters['category'] === null
            ? null
            : Category::query()->with('children.children')->where('public_id', $filters['category'])->first();

        $products = Product::query()
            ->with([
                'category:id,name',
                'brand:id,name',
                // The listing image only: position one, and never a video.
                'media' => fn ($query) => $query->where('type', ProductMedia::TYPE_IMAGE)->where('position', 1),
            ])
            ->withCount(['variants', 'media'])
            ->when($filters['search'] !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('name', 'ilike', $pattern)
                    ->orWhere('sku', 'ilike', $pattern)
                    ->orWhere('barcode', 'ilike', $pattern),
            ))
            ->when($filters['status'] !== null, fn (Builder $query) => $query->where('status', $filters['status']))

            // A category includes its subcategories, as it does for partners.
            ->when($filters['category'] !== null, fn (Builder $query) => $query->whereIn('category_id', $category?->descendantIds() ?? []))
            ->when($filters['brand'] !== null, fn (Builder $query) => $query->whereHas(
                'brand',
                fn (Builder $inner) => $inner->where('public_id', $filters['brand']),
            ))
            ->when($filters['channel'] !== null, function (Builder $query) use ($filters) {
                $status = ProductStatus::from((string) $filters['channel']);

                $query->where(SalesChannel::from($status->axis())->column(), $status->value);
            })
            ->when($filters['featured'] === 'yes', fn (Builder $query) => $query->where('is_featured', true))
            ->when($filters['featured'] === 'no', fn (Builder $query) => $query->where('is_featured', false))
            ->when(
                in_array($sort, self::SORTABLE, true),
                fn (Builder $query) => $query->orderBy($sort, $direction),
                fn (Builder $query) => $query->orderByDesc('updated_at'),
            )
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Product $product) => $this->row($product));

        return Inertia::render('admin/catalog/products/index', [
            'products' => $products,
            'filters' => Arr::except($filters, 'search'),
            'filter_options' => $this->options(),
            'can' => $this->abilities($actor),

            /*
             * What the bulk bar offers this person (§11.2): only the lifecycle
             * targets some move they hold a permission for reaches, and the
             * channel and featuring switches their permissions cover. The server
             * refuses the rest regardless, per product.
             */
            'bulk' => [
                'max' => BulkUpdateProducts::MAX_PRODUCTS,
                'transitions' => collect(ProductStatus::lifecycle())
                    ->filter(fn (ProductStatus $to) => CatalogPolicy::canMoveAnyProductTo($actor, $to))
                    ->map(fn (ProductStatus $to) => ['value' => $to->value, 'requires_reason' => $to->requiresReason()])
                    ->values()
                    ->all(),
                'enable_channels' => CatalogPolicy::canSetChannel($actor, true),
                'disable_channels' => CatalogPolicy::canSetChannel($actor, false),
                'feature' => CatalogPolicy::canPublish($actor),

                // Filing products under a category or brand (§11.3).
                'assign' => CatalogPolicy::canEdit($actor),
            ],
        ]);
    }

    /**
     * The list's filters, each held to the values it may take. Anything else is
     * dropped rather than passed to a query, because a query string is somebody's
     * input.
     *
     * @return array{search: string, status: string|null, category: string|null, brand: string|null, channel: string|null, featured: string|null}
     */
    protected function listFilters(Request $request): array
    {
        $pick = function (string $key, array $allowed) use ($request): ?string {
            $value = $request->string($key)->toString();

            return in_array($value, $allowed, true) ? $value : null;
        };

        $publicId = function (string $key) use ($request): ?string {
            $value = $request->string($key)->toString();

            return preg_match('/^[0-9A-Za-z]{10,40}$/', $value) === 1 ? $value : null;
        };

        $channelStatuses = array_values(array_map(
            fn (ProductStatus $status) => $status->value,
            array_filter(ProductStatus::cases(), fn (ProductStatus $status) => $status->axis() !== ProductStatus::AXIS_LIFECYCLE),
        ));

        return [
            'search' => trim($request->string('search')->toString()),
            'status' => $pick('status', array_map(fn (ProductStatus $status) => $status->value, ProductStatus::lifecycle())),
            'category' => $publicId('category'),
            'brand' => $publicId('brand'),
            'channel' => $pick('channel', $channelStatuses),
            'featured' => $pick('featured', ['yes', 'no']),
        ];
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canCreate($actor), 403);

        return Inertia::render('admin/catalog/products/form', [
            'product' => null,
            'options' => $this->options(),
            'can' => $this->abilities($actor),

            // Staff-only: lets the new product be put into a sourcing group
            // from a pop-up while it is being added.
            'sourcing' => app(SourcingGroupOptions::class)->forReview($actor, null),

            // Media and variations are added once the product exists to hang
            // them on.
            'media' => [],
            'media_limits' => $this->mediaLimits(),
            'price_tiers' => [],
            'eligibility' => null,
            'package_options' => [],
            'merchandising' => null,
            'seo_preview' => null,
            'transitions' => [],
            'history' => [],
            'variants' => [],
            'attributes' => [],
            'variant_builder_max' => GenerateVariants::MAX_COMBINATIONS,
        ]);
    }

    public function store(SaveProductRequest $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canCreate($actor), 403);

        $product = $this->products->create($actor, $request->productAttributes());

        $assigned = $this->assignToSourcingGroup($actor, $product, $request->validated('sourcing_group_id'));

        // One toast: the product is created either way; a refused group
        // assignment is said plainly beside it as a warning.
        Inertia::flash('toast', [
            'type' => is_string($assigned) ? 'warning' : 'success',
            'message' => trim(__('catalog.products.created', ['name' => $product->name]).' '.match (true) {
                $assigned === true => __('catalog.products.sourcing_assigned'),
                is_string($assigned) => $assigned,
                default => '',
            }),
        ]);

        return redirect()->route('admin.catalog.products.edit', $product->public_id);
    }

    /**
     * Put a just-created product into the chosen sourcing group.
     *
     * Never blocks the product: the product already exists, so a refusal (no
     * permission, inactive group) is reported beside the success rather than
     * undoing it. Returns true on success, a message on refusal, null when no
     * group was chosen.
     */
    protected function assignToSourcingGroup(User $actor, Product $product, ?string $groupId): bool|string|null
    {
        if (blank($groupId)) {
            return null;
        }

        $group = ProductSourcingGroup::query()->where('public_id', $groupId)->first();

        if ($group === null || ! $actor->can('update', $group)) {
            return __('catalog.products.sourcing_forbidden');
        }

        try {
            app(ManageSourcingGroups::class)->addProduct($actor, $group, $product, 'Assigned when the product was added.');
        } catch (SourcingGroupRefused $exception) {
            return $exception->getMessage();
        }

        return true;
    }

    public function edit(Request $request, string $product): Response
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canViewAny($actor), 403);

        $record = $this->product($product);
        $record->loadMissing(['socialImage', 'media', 'brand']);
        $record->loadMissing(['contents' => fn ($query) => $query->with(['creator:id,name', 'attachment'])]);

        return Inertia::render('admin/catalog/products/form', [
            'product' => $this->detail($record),
            'options' => $this->options(),
            'can' => $this->abilities($actor),

            /*
             * The lifecycle moves this person may make from here (§11.2). Only
             * those: offering a button the server will refuse teaches people
             * the screen lies. The server still checks every one.
             */
            'transitions' => collect($record->status->transitionsTo())
                ->filter(fn (ProductStatus $to) => CatalogPolicy::canMoveProduct($actor, $record->status, $to))
                ->map(fn (ProductStatus $to) => [
                    'value' => $to->value,
                    'tone' => $to->tone(),
                    'requires_reason' => $to->requiresReason(),
                ])
                ->values()
                ->all(),

            'history' => $record->statusHistory()
                ->with('actor:id,name')
                ->limit(20)
                ->get()
                ->map(fn (ProductStatusChange $change) => [
                    'id' => $change->id,
                    'from' => $change->from_status?->value,
                    'to' => $change->to_status->value,
                    'to_tone' => $change->to_status->tone(),
                    'actor' => $change->actor?->name,

                    // A move the system made carries a translation key rather
                    // than somebody's words, so it reads in this reader's language.
                    'reason' => $change->actor_id === null && in_array($change->reason, [SyncProductStockStatus::RAN_OUT, SyncProductStockStatus::RESTOCKED], true)
                        ? __($change->reason)
                        : $change->reason,
                    'at' => $change->created_at->toIso8601String(),
                ])
                ->all(),

            /*
             * What a partner website would render for this product (§34.3),
             * built by the same service the storefront API will use. The
             * schema's price is the suggested selling price, standing in for a
             * partner's own; with none set, the preview has no offer rather than
             * an invented one. Paths are relative: the host is the partner's.
             */
            'seo_preview' => [
                'metadata' => $this->seo->metadata($record),
                'schema' => json_encode(
                    $this->seo->schema($record, '/products/'.$record->slug, $record->suggested_selling_price),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ],

            // What it recommends, in order, and whether it is featured (§11.1).
            'merchandising' => [
                'is_featured' => $record->is_featured,
                'featured_at' => $record->featured_at?->toIso8601String(),
                'related' => $record->relatedProducts()
                    ->get(['products.public_id', 'products.name', 'products.sku', 'products.status'])
                    ->map(fn (Product $related) => [
                        'id' => $related->public_id,
                        'name' => $related->name,
                        'sku' => $related->sku,
                        'status' => $related->status->value,
                        'status_tone' => $related->status->tone(),
                    ])
                    ->all(),
            ],

            /*
             * Products to add as related, evaluated only when the editor asks by
             * partial reload, and never the product itself.
             */
            'related_matches' => Inertia::optional(fn () => $this->relatedMatches($request, $record)),

            // Who may see it: by package, and optionally by account (§11.1).
            'eligibility' => [
                'package_scope' => $record->package_scope->value,
                'package_ids' => $record->eligiblePackages()->pluck('packages.public_id')->all(),
                'account_scope' => $record->account_scope->value,
                'accounts' => $record->eligibleAccounts()
                    ->orderBy('business_accounts.name')
                    ->get(['business_accounts.public_id', 'business_accounts.name'])
                    ->map(fn (BusinessAccount $account) => ['id' => $account->public_id, 'name' => $account->name])
                    ->all(),
            ],
            'package_options' => Package::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['public_id', 'name', 'is_active'])
                ->map(fn (Package $package) => [
                    'value' => $package->public_id,
                    'label' => $package->name,
                    'is_available' => $package->is_active,
                ])
                ->all(),

            /*
             * Account search for the allow-list, evaluated only when the editor
             * asks for it by partial reload — never on an ordinary page load,
             * and never more than a handful of names.
             */
            'account_matches' => Inertia::optional(fn () => $this->accountMatches($request)),

            // Quantity pricing per scope: the product, then each variation.
            'price_tiers' => $this->priceTiers($record),

            // Images and videos, in the order storefronts show them (§11.1).
            'media' => $record->media()
                ->with('variant.values.attribute')
                ->get()
                ->map(fn (ProductMedia $media) => $this->mediaRow($media))
                ->all(),
            'media_limits' => $this->mediaLimits(),

            // Variations beside the product they belong to (§11.1).
            'variants' => $record->variants()
                ->with(['values.attribute', 'product'])
                ->get()
                ->map(fn (ProductVariant $variant) => $this->variant($variant))
                ->all(),

            // The most combinations the variation builder creates at once.
            'variant_builder_max' => GenerateVariants::MAX_COMBINATIONS,

            'attributes' => ProductAttribute::query()
                ->with('values')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (ProductAttribute $attribute) => [
                    'id' => $attribute->public_id,
                    'name' => $attribute->name,
                    'values' => $attribute->values
                        ->map(fn (ProductAttributeValue $value) => [
                            'id' => $value->public_id,
                            'value' => $value->value,
                        ])
                        ->all(),
                ])
                ->all(),

            // Updates published for this product, newest first (new feature).
            'content' => $record->contents->map(fn (ProductContent $content) => $this->contentRow($content))->all(),
            'content_limits' => [
                'image_types' => PublishProductContent::IMAGE_TYPES,
                'file_types' => PublishProductContent::FILE_TYPES,
                'image_max_mb' => $this->megabytes(PublishProductContent::IMAGE_MAX_BYTES),
                'file_max_mb' => $this->megabytes(PublishProductContent::FILE_MAX_BYTES),
            ],
        ]);
    }

    public function update(SaveProductRequest $request, string $product): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canEdit($actor), 403);

        $updated = $this->products->update($actor, $this->product($product), $request->productAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.products.updated', ['name' => $updated->name])]);

        return back();
    }

    /**
     * Move a product to Trash — any status, with a reason always on record
     * (urgent product-management fix). Reversible: see {@see ProductTrashController}
     * for restoring it, or permanently deleting it once nothing real uses it.
     */
    public function destroy(Request $request, string $product): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canDelete($actor), 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->products->trash($actor, $this->product($product), $validated['reason']);
        } catch (CatalogRefused $refused) {
            return back()->withErrors(['product' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('catalog.products.trashed')]);

        return redirect()->route('admin.catalog.products.index');
    }

    /**
     * The product's barcode, as a downloadable EAN-13 SVG.
     *
     * Anyone who may see the catalogue may print its labels; this is read
     * only, so it asks no more of the policy than the editor already does.
     */
    public function barcode(Request $request, string $product): HttpResponse
    {
        $actor = $this->actor($request);

        abort_unless(CatalogPolicy::canViewAny($actor), 403);

        $record = $this->product($product);

        abort_if($record->barcode === null, 404);

        $svg = ProductBarcode::toSvg($record->barcode);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="'.$record->sku.'-barcode.svg"',
        ]);
    }

    /**
     * One product as the list shows it — its listing image, both channels and
     * how much hangs off it, so the list answers "which of these is not ready"
     * without opening each editor.
     *
     * @return array<string, mixed>
     */
    protected function row(Product $product): array
    {
        $image = $product->media->first();

        return [
            'id' => $product->public_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'category' => $product->category->name,
            'brand' => $product->brand?->name,
            'wholesale_price' => $product->wholesale_price->jsonSerialize(),
            'status' => $product->status->value,
            'status_tone' => $product->status->tone(),
            'is_featured' => $product->is_featured,
            'channels' => array_map(fn (SalesChannel $channel) => [
                'channel' => $channel->value,
                'status' => $product->channelStatus($channel)->value,
                'tone' => $product->channelStatus($channel)->tone(),
                'enabled' => $product->sellsThrough($channel),
            ], SalesChannel::cases()),
            'image_url' => $image === null ? null : $this->mediaStore->url($image->path, $image->disk),
            'media_count' => (int) $product->getAttribute('media_count'),
            'variants_count' => (int) $product->getAttribute('variants_count'),
            'updated_at' => $product->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(Product $product): array
    {
        return [
            'id' => $product->public_id,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'category_id' => $product->category->public_id,
            'brand_id' => $product->brand?->public_id,

            // The server's own rendering of the amount; the form edits its
            // .decimal string and the server converts back on submit (§36.1).
            'base_cost' => $product->base_cost->jsonSerialize(),
            'wholesale_price' => $product->wholesale_price->jsonSerialize(),

            'meta_title' => $product->meta_title,
            'meta_description' => $product->meta_description,
            'meta_keywords' => $product->meta_keywords,
            'mpn' => $product->mpn,
            'item_condition' => $product->item_condition->value,
            'social_image_id' => $product->socialImage?->public_id,

            'min_order_quantity' => $product->min_order_quantity,
            'max_order_quantity' => $product->max_order_quantity,
            'suggested_selling_price' => $product->suggested_selling_price?->jsonSerialize(),
            'minimum_selling_price' => $product->minimum_selling_price?->jsonSerialize(),
            'maximum_selling_price' => $product->maximum_selling_price?->jsonSerialize(),

            // Physical logistics and packaging (beta-critical batch, Commit
            // 1). Grams and centimetres, the one canonical unit each is
            // stored in (§D26-style: no float, no ambiguous minor unit).
            'net_weight_grams' => $product->net_weight_grams,
            'shipping_weight_grams' => $product->shipping_weight_grams,
            'length_cm' => $product->length_cm,
            'width_cm' => $product->width_cm,
            'height_cm' => $product->height_cm,
            'ships_by_box' => $product->ships_by_box,
            'pieces_per_box' => $product->pieces_per_box,
            'box_weight_grams' => $product->box_weight_grams,
            'box_length_cm' => $product->box_length_cm,
            'box_width_cm' => $product->box_width_cm,
            'box_height_cm' => $product->box_height_cm,
            'is_fragile' => $product->is_fragile,

            'status' => $product->status->value,
            'status_tone' => $product->status->tone(),
            'published_at' => $product->published_at?->toIso8601String(),

            // Each channel separately: they are separate decisions (§11.1).
            'channels' => array_map(fn (SalesChannel $channel) => [
                'channel' => $channel->value,
                'status' => $product->channelStatus($channel)->value,
                'tone' => $product->channelStatus($channel)->tone(),
                'enabled' => $product->sellsThrough($channel),
            ], SalesChannel::cases()),
            'updated_at' => $product->updated_at->toIso8601String(),
        ];
    }

    /**
     * One image or video as the editor shows it — the storefront contract's
     * url, alt, position and type, plus what an administrator needs beside it.
     *
     * @return array<string, mixed>
     */
    protected function mediaRow(ProductMedia $media): array
    {
        return [
            'id' => $media->public_id,
            'type' => $media->type,
            'url' => $this->mediaStore->url($media->path, $media->disk),
            'alt_text' => $media->alt_text,
            'position' => $media->position,
            'mime_type' => $media->mime_type,
            'size_bytes' => $media->size_bytes,
            'width' => $media->width,
            'height' => $media->height,
            'variant_id' => $media->variant?->public_id,
            'variant_label' => $media->variant?->values
                ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                ->pluck('value')
                ->implode(' / '),
        ];
    }

    /**
     * One published update, with its attachment's address resolved against
     * whichever disk it actually lives on (new feature).
     *
     * @return array<string, mixed>
     */
    protected function contentRow(ProductContent $content): array
    {
        $attachment = $content->attachment;

        return [
            'id' => $content->public_id,
            'title' => $content->title,
            'body' => $content->body,
            'published_at' => $content->published_at->toIso8601String(),
            'creator' => $content->creator?->name,
            'attachment' => $attachment === null ? null : [
                'url' => $this->storage->url($attachment),
                'mime_type' => $attachment->mime_type,
                'is_image' => str_starts_with($attachment->mime_type, 'image/'),
            ],
        ];
    }

    /**
     * The limits the upload form states — the same figures the server enforces,
     * never above what PHP accepts.
     *
     * @return array<string, mixed>
     */
    protected function mediaLimits(): array
    {
        return [
            'image_types' => ProductMediaStore::IMAGE_TYPES,
            'video_types' => ProductMediaStore::VIDEO_TYPES,
            // Strings, already formatted: "2", "2.5". A float in a prop comes
            // back from JSON as whatever it happens to round-trip to.
            'image_max_mb' => $this->megabytes(ProductMediaStore::maxBytesFor(ProductMedia::TYPE_IMAGE)),
            'video_max_mb' => $this->megabytes(ProductMediaStore::maxBytesFor(ProductMedia::TYPE_VIDEO)),
            'max_items' => ManageProductMedia::MAX_PER_PRODUCT,
        ];
    }

    /**
     * The quantity pricing of the product and of each variation, as the server
     * resolves it.
     *
     * `applies` is false for a band that no longer undercuts its base — a tier
     * written before the base price was cut. The resolver charges the base for
     * it, and the screen says so rather than showing a price nobody pays.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function priceTiers(Product $product): array
    {
        $scopes = [null, ...$product->variants()->with(['values.attribute', 'product'])->get()->all()];

        return array_map(function (?ProductVariant $variant) use ($product) {
            $base = $this->prices->basePrice($product, $variant);

            $own = ProductPriceTier::query()
                ->where('product_id', $product->id)
                ->where('product_variant_id', $variant?->id)
                ->orderBy('min_quantity')
                ->get();

            return [
                'variant_id' => $variant?->public_id,
                'label' => $variant === null
                    ? null
                    : $variant->values
                        ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                        ->pluck('value')
                        ->implode(' / '),
                'base_price' => $base->jsonSerialize(),
                'tiers' => $own
                    ->map(fn (ProductPriceTier $tier) => [
                        'min_quantity' => $tier->min_quantity,
                        'unit_price' => $tier->unit_price->jsonSerialize(),
                        'applies' => $tier->unit_price->lessThanOrEqualTo($base),
                    ])
                    ->all(),
            ];
        }, $scopes);
    }

    /**
     * Up to ten other products whose name or SKU matches the search.
     *
     * @return array<int, array{id: string, name: string, sku: string, status: string}>
     */
    protected function relatedMatches(Request $request, Product $product): array
    {
        $search = trim($request->string('related_search')->toString());

        if (mb_strlen($search) < 2) {
            return [];
        }

        $pattern = '%'.addcslashes($search, '%_\\').'%';

        return Product::query()
            ->whereKeyNot($product->id)
            ->where(fn (Builder $query) => $query->where('name', 'ilike', $pattern)->orWhere('sku', 'ilike', $pattern))
            ->orderBy('name')
            ->limit(10)
            ->get(['public_id', 'name', 'sku', 'status'])
            ->map(fn (Product $match) => [
                'id' => $match->public_id,
                'name' => $match->name,
                'sku' => $match->sku,
                'status' => $match->status->value,
            ])
            ->all();
    }

    /**
     * Up to ten business accounts whose name matches the search.
     *
     * @return array<int, array{id: string, name: string, status: string}>
     */
    protected function accountMatches(Request $request): array
    {
        $search = trim($request->string('account_search')->toString());

        if (mb_strlen($search) < 2) {
            return [];
        }

        return BusinessAccount::query()
            ->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')
            ->orderBy('name')
            ->limit(10)
            ->get(['public_id', 'name', 'status'])
            ->map(fn (BusinessAccount $account) => [
                'id' => $account->public_id,
                'name' => $account->name,
                'status' => $account->status->value,
            ])
            ->all();
    }

    public static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, '.', ''), '0'), '.');
    }

    /**
     * One variation as the editor shows it.
     *
     * The effective price is the server's answer to "what does this variant
     * sell at", so the page never decides whether an override applies.
     *
     * @return array<string, mixed>
     */
    protected function variant(ProductVariant $variant): array
    {
        $values = $variant->values
            ->sortBy(fn (ProductAttributeValue $value) => [$value->attribute->sort_order, $value->attribute->name])
            ->values();

        return [
            'id' => $variant->public_id,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'label' => $values->pluck('value')->implode(' / '),
            'values' => $values
                ->map(fn (ProductAttributeValue $value) => [
                    'attribute' => $value->attribute->name,
                    'value' => $value->value,
                ])
                ->all(),
            // The override only, for the edit form's default value and for
            // re-posting it unchanged (e.g. on activation toggle) — never the
            // effective price, which falls back to the product's own figure
            // and would turn "no override" into an explicit one if resent.
            'wholesale_price_override' => $variant->wholesale_price?->jsonSerialize(),
            'base_cost_override' => $variant->base_cost?->jsonSerialize(),
            'wholesale_price' => $variant->effectiveWholesalePrice()->jsonSerialize(),
            'overrides_price' => $variant->wholesale_price !== null,
            'is_active' => $variant->is_active,

            // This variant's own override only (null = inherits the
            // product's figure), for the edit form's default value --
            // never the effective figure, the same rule as the price
            // fields above (beta-critical batch, Commit 1).
            'net_weight_grams' => $variant->net_weight_grams,
            'shipping_weight_grams' => $variant->shipping_weight_grams,
            'length_cm' => $variant->length_cm,
            'width_cm' => $variant->width_cm,
            'height_cm' => $variant->height_cm,
            'ships_by_box' => $variant->ships_by_box,
            'pieces_per_box' => $variant->pieces_per_box,
            'box_weight_grams' => $variant->box_weight_grams,
            'box_length_cm' => $variant->box_length_cm,
            'box_width_cm' => $variant->box_width_cm,
            'box_height_cm' => $variant->box_height_cm,
            'is_fragile' => $variant->is_fragile,
        ];
    }

    /**
     * What the editor's selects offer.
     *
     * Switched-off categories and brands are included and labelled rather than
     * dropped: a product already filed under one must still show where it is,
     * and a draft may be prepared before its range goes live.
     *
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        $categories = Category::query()
            ->with('parent')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'categories' => $categories
                ->sortBy(fn (Category $category) => [
                    $category->parent->sort_order ?? $category->sort_order,
                    $category->parent_id ?? $category->id,
                    $category->parent_id === null ? 0 : 1,
                    $category->sort_order,
                ])
                ->map(fn (Category $category) => [
                    'value' => $category->public_id,
                    'label' => $category->parent === null
                        ? $category->name
                        : $category->parent->name.' › '.$category->name,
                    'is_available' => $category->isAvailable(),
                ])
                ->values()
                ->all(),

            'brands' => Brand::query()
                ->orderBy('name')
                ->get(['public_id', 'name', 'is_active'])
                ->map(fn (Brand $brand) => [
                    'value' => $brand->public_id,
                    'label' => $brand->name,
                    'is_available' => $brand->is_active,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function abilities(User $actor): array
    {
        return [
            'create' => CatalogPolicy::canCreate($actor),
            'edit' => CatalogPolicy::canEdit($actor),
            'delete' => CatalogPolicy::canDelete($actor),
            'publish' => CatalogPolicy::canPublish($actor),
            'enable_channels' => CatalogPolicy::canSetChannel($actor, true),
            'disable_channels' => CatalogPolicy::canSetChannel($actor, false),
        ];
    }

    protected function product(string $publicId): Product
    {
        /** @var Product $product */
        $product = Product::query()
            ->with(['category', 'brand'])
            ->where('public_id', $publicId)
            ->firstOrFail();

        return $product;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
