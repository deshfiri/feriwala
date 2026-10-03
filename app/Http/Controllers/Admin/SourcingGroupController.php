<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Sourcing\Actions\ManageSourcingGroups;
use App\Domain\Sourcing\Exceptions\SourcingGroupRefused;
use App\Domain\Sourcing\Models\ProductSourcingGroup;
use App\Domain\Sourcing\Models\ProductSourcingGroupProduct;
use App\Domain\Sourcing\Models\ProductSourcingVariantMapping;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Product Sourcing Groups: which catalogue products can fulfil one another's
 * orders (platform staff only).
 *
 * Every change is delegated to {@see ManageSourcingGroups}, which owns the
 * rules and the audit trail; this controller validates, authorises and shapes
 * the response. No Supplier rate appears here -- the screen is about
 * equivalence, and rates stay behind `supplier_pricing.view`.
 */
class SourcingGroupController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ProductSourcingGroup::class);

        $status = $request->string('status')->toString();

        $groups = ProductSourcingGroup::query()
            ->withCount([
                'products as products_count' => fn ($query) => $query->active(),
                'variantMappings as mappings_count' => fn ($query) => $query->active(),
            ])
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $status === 'active'))
            ->when($request->string('search')->toString(), fn ($query, string $search) => $query
                ->where(fn ($inner) => $inner
                    ->where('code', 'ilike', "%{$search}%")
                    ->orWhere('name_en', 'ilike', "%{$search}%")
                    ->orWhere('name_bn', 'ilike', "%{$search}%")))
            ->orderBy('name_en')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ProductSourcingGroup $group) => [
                'id' => $group->public_id,
                'code' => $group->code,
                'name_en' => $group->name_en,
                'name_bn' => $group->name_bn,
                'is_active' => $group->is_active,
                'products_count' => (int) $group->getAttribute('products_count'),
                'mappings_count' => (int) $group->getAttribute('mappings_count'),
            ]);

        /** @var User $actor */
        $actor = $request->user();

        return Inertia::render('admin/sourcing-groups/index', [
            'groups' => $groups,
            'can' => ['create' => $actor->can('create', ProductSourcingGroup::class)],
        ]);
    }

    public function store(Request $request, ManageSourcingGroups $manage): RedirectResponse
    {
        Gate::authorize('create', ProductSourcingGroup::class);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique(ProductSourcingGroup::class, 'code')],
            'name_en' => ['required', 'string', 'max:255'],
            'name_bn' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $group = $manage->create($this->actor($request), $validated);

        return to_route('admin.sourcing-groups.show', $group)
            ->with('success', __('sourcing.messages.created'));
    }

    public function show(Request $request, ProductSourcingGroup $group): Response
    {
        Gate::authorize('view', $group);

        /** @var User $actor */
        $actor = $request->user();

        $memberships = $group->products()->active()->with(['product.variants.values'])->orderByDesc('is_canonical')->get();
        $productIds = $memberships->pluck('product_id');
        $canonical = $memberships->firstWhere('is_canonical', true);

        $products = $memberships->map(fn (ProductSourcingGroupProduct $membership) => [
            'id' => $membership->product->public_id,
            'name' => $membership->product->name,
            'sku' => $membership->product->sku,
            'is_canonical' => $membership->is_canonical,
            'added_reason' => $membership->added_reason,
            'variants' => $membership->product->variants->map(fn (ProductVariant $variant) => [
                'id' => $variant->public_id,
                'label' => $this->variantLabel($variant),
            ])->values()->all(),
        ])->values();

        $mappings = $group->variantMappings()->active()
            ->with(['product', 'variant.values', 'canonicalVariant.values'])
            ->get()
            ->map(fn (ProductSourcingVariantMapping $mapping) => [
                'id' => $mapping->public_id,
                'product' => ['id' => $mapping->product->public_id, 'name' => $mapping->product->name],
                'variant' => $mapping->variant === null ? null : $this->variantLabel($mapping->variant),
                'canonical_variant' => $mapping->canonicalVariant === null ? null : $this->variantLabel($mapping->canonicalVariant),
                'reason' => $mapping->added_reason,
            ])->values();

        $offers = SupplierOffer::query()
            ->with(['supplier', 'product', 'variant.values'])
            ->whereIn('product_id', $productIds)
            ->get()
            ->map(fn (SupplierOffer $offer) => [
                'id' => $offer->public_id,
                'supplier' => $offer->supplier->business_name,
                'product' => $offer->product->name,
                'variant' => $offer->variant === null ? null : $this->variantLabel($offer->variant),
                'status' => $offer->status->value,
                'supply_mode' => $offer->supply_mode->value,
            ])->values();

        $stock = StockItem::query()
            ->with(['warehouse', 'product', 'variant.values'])
            ->whereIn('product_id', $productIds)
            ->get()
            ->map(fn (StockItem $item) => [
                'id' => $item->public_id,
                'warehouse' => $item->warehouse->name,
                'product' => $item->product->name,
                'variant' => $item->variant === null ? null : $this->variantLabel($item->variant),
                'available' => (int) $item->available,
            ])->values();

        $history = AuditLog::query()
            ->with('actor:id,name')
            ->where('auditable_type', ProductSourcingGroup::class)
            ->where('auditable_id', $group->id)
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor?->name,
                'reason' => $log->reason,
                'before' => $log->before,
                'after' => $log->after,
                'at' => $log->created_at->toIso8601String(),
            ])->values();

        $search = trim($request->string('product_search')->toString());

        $matches = mb_strlen($search) < 2 ? [] : Product::query()
            ->whereNotIn('id', ProductSourcingGroupProduct::query()->active()->select('product_id'))
            ->where(fn ($query) => $query
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('sku', 'ilike', "%{$search}%"))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'public_id', 'name', 'sku'])
            ->map(fn (Product $product) => ['id' => $product->public_id, 'name' => $product->name, 'sku' => $product->sku])
            ->all();

        return Inertia::render('admin/sourcing-groups/show', [
            'group' => [
                'id' => $group->public_id,
                'code' => $group->code,
                'name_en' => $group->name_en,
                'name_bn' => $group->name_bn,
                'description' => $group->description,
                'is_active' => $group->is_active,
            ],
            'products' => $products,
            'canonical_variants' => $canonical === null ? [] : $canonical->product->variants->map(fn (ProductVariant $variant) => [
                'id' => $variant->public_id,
                'label' => $this->variantLabel($variant),
            ])->values()->all(),
            'mappings' => $mappings,
            'offers' => $offers,
            'stock' => $stock,
            'history' => $history,
            'product_matches' => $matches,
            'can' => [
                'update' => $actor->can('update', $group),
                'toggle' => $actor->can('toggle', $group),
            ],
        ]);
    }

    public function update(Request $request, ProductSourcingGroup $group, ManageSourcingGroups $manage): RedirectResponse
    {
        Gate::authorize('update', $group);

        $validated = $request->validate([
            'name_en' => ['required', 'string', 'max:255'],
            'name_bn' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $manage->update($this->actor($request), $group, $validated);

        return back()->with('success', __('sourcing.messages.updated'));
    }

    public function toggle(Request $request, ProductSourcingGroup $group, ManageSourcingGroups $manage): RedirectResponse
    {
        Gate::authorize('toggle', $group);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->guard(fn () => $manage->setActive($this->actor($request), $group, (bool) $validated['is_active'], $validated['reason']));

        return back()->with('success', __('sourcing.messages.updated'));
    }

    public function addProduct(Request $request, ProductSourcingGroup $group, ManageSourcingGroups $manage): RedirectResponse
    {
        Gate::authorize('update', $group);

        $validated = $request->validate([
            'product_id' => ['required', 'string', Rule::exists(Product::class, 'public_id')],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $product = Product::query()->where('public_id', $validated['product_id'])->firstOrFail();

        $this->guard(fn () => $manage->addProduct($this->actor($request), $group, $product, $validated['reason']));

        return back()->with('success', __('sourcing.messages.product_added'));
    }

    public function removeProduct(Request $request, ProductSourcingGroup $group, Product $product, ManageSourcingGroups $manage): RedirectResponse
    {
        Gate::authorize('update', $group);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->guard(fn () => $manage->removeProduct($this->actor($request), $group, $product, $validated['reason']));

        return back()->with('success', __('sourcing.messages.product_removed'));
    }

    public function mapVariant(Request $request, ProductSourcingGroup $group, ManageSourcingGroups $manage): RedirectResponse
    {
        Gate::authorize('update', $group);

        $validated = $request->validate([
            'product_id' => ['required', 'string', Rule::exists(Product::class, 'public_id')],
            'variant_id' => ['nullable', 'string', Rule::exists(ProductVariant::class, 'public_id')],
            'canonical_variant_id' => ['nullable', 'string', Rule::exists(ProductVariant::class, 'public_id')],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $product = Product::query()->where('public_id', $validated['product_id'])->firstOrFail();
        $variant = filled($validated['variant_id'] ?? null)
            ? ProductVariant::query()->where('public_id', $validated['variant_id'])->firstOrFail() : null;
        $canonical = filled($validated['canonical_variant_id'] ?? null)
            ? ProductVariant::query()->where('public_id', $validated['canonical_variant_id'])->firstOrFail() : null;

        $this->guard(fn () => $manage->mapVariant($this->actor($request), $group, $product, $variant, $canonical, $validated['reason']));

        return back()->with('success', __('sourcing.messages.mapped'));
    }

    public function unmapVariant(Request $request, ProductSourcingGroup $group, ProductSourcingVariantMapping $mapping, ManageSourcingGroups $manage): RedirectResponse
    {
        Gate::authorize('update', $group);
        abort_unless($mapping->sourcing_group_id === $group->id, 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->guard(fn () => $manage->unmapVariant($this->actor($request), $mapping, $validated['reason']));

        return back()->with('success', __('sourcing.messages.unmapped'));
    }

    /**
     * A refusal from the action reaches the form as a validation error, in the
     * action's own plain words.
     */
    protected function guard(callable $change): void
    {
        try {
            $change();
        } catch (SourcingGroupRefused $exception) {
            throw ValidationException::withMessages(['group' => $exception->getMessage()]);
        }
    }

    /**
     * "SKU — Black / M": enough for staff to tell two variations apart without
     * guessing from the SKU alone.
     */
    protected function variantLabel(ProductVariant $variant): string
    {
        $values = $variant->values->pluck('value')->filter()->implode(' / ');

        return $values === '' ? $variant->sku : $variant->sku.' — '.$values;
    }

    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
