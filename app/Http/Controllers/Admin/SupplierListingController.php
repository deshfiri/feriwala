<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Catalog\Models\Category;
use App\Domain\Supplier\Actions\DecideSupplierListing;
use App\Domain\Supplier\Actions\RequestSupplierListingCorrection;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingItem;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Staff review of Supplier Product Listing Requests (D25, P13-9, P13-11).
 *
 * The Supplier Rate is shown only to staff holding `supplier_pricing.view`,
 * and a Platform Rate is set only by staff holding `supplier_pricing.edit` —
 * `supplier_listing.approve` alone decides a listing but does not open the
 * confidential-pricing boundary.
 */
class SupplierListingController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SupplierProductListing::class);

        $status = $request->string('status')->toString();

        $listings = SupplierProductListing::query()
            ->with(['supplier:id,public_id,business_name', 'category:id,name'])
            ->withCount('items')
            ->when(ListingStatus::tryFrom($status) !== null, fn ($q) => $q->where('status', $status))
            ->when($request->filled('supplier'), fn ($q) => $q->whereHas('supplier', fn ($s) => $s->where('public_id', $request->string('supplier')->toString())))
            ->when($request->filled('category'), fn ($q) => $q->whereHas('category', fn ($c) => $c->where('public_id', $request->string('category')->toString())))
            ->when($request->filled('submitted_from'), fn ($q) => $q->where('submitted_at', '>=', $request->date('submitted_from')))
            ->when($request->filled('submitted_to'), fn ($q) => $q->where('submitted_at', '<=', $request->date('submitted_to')))
            ->where('status', '!=', ListingStatus::Draft->value)
            ->orderBy('submitted_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SupplierProductListing $listing) => [
                'id' => $listing->public_id,
                'reference' => $listing->reference,
                'product_name' => $listing->product_name,
                'supplier' => $listing->supplier->business_name,
                'category' => $listing->category->name ?? $listing->category_suggestion,
                'items_count' => $listing->items_count,
                'status' => $listing->status->value,
                'status_label' => $listing->status->label(),
                'status_tone' => $listing->status->tone(),
                'submitted_at' => $listing->submitted_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/supplier-listings/index', [
            'listings' => $listings,
            'statuses' => array_map(fn (ListingStatus $s) => ['value' => $s->value, 'label' => $s->label()], ListingStatus::cases()),
        ]);
    }

    public function show(Request $request, SupplierProductListing $listing): Response
    {
        Gate::authorize('view', $listing);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $mayViewPricing = $reviewer->can(PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::View));
        $mayEditPricing = $reviewer->can(PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::Edit));

        $listing->load(['supplier', 'category', 'brand', 'items', 'statusHistory.changedBy']);

        return Inertia::render('admin/supplier-listings/show', [
            'listing' => [
                'id' => $listing->public_id,
                'reference' => $listing->reference,
                'product_name' => $listing->product_name,
                'description' => $listing->description,
                'category' => $listing->category?->name,
                'category_suggestion' => $listing->category_suggestion,
                'brand' => $listing->brand?->name,
                'brand_suggestion' => $listing->brand_suggestion,
                'supplier_note' => $listing->supplier_note,
                'status' => $listing->status->value,
                'status_label' => $listing->status->label(),
                'status_tone' => $listing->status->tone(),
                'awaits_decision' => $listing->status === ListingStatus::UnderReview,
                'can_review' => Gate::allows('review', $listing),
                'can_decide' => Gate::allows('decide', $listing) && $mayEditPricing,
                'may_view_pricing' => $mayViewPricing,
                'connected_product' => $listing->connectedProduct?->public_id,
                'items' => $listing->items->map(fn (SupplierProductListingItem $item) => [
                    'id' => $item->public_id,
                    'variant_label' => $item->variant_label,
                    'supplier_sku' => $item->supplier_sku,
                    'supplier_rate' => $mayViewPricing ? $item->supplier_rate->jsonSerialize() : null,
                    'available_quantity' => $item->available_quantity,
                    'minimum_supply_quantity' => $item->minimum_supply_quantity,
                    'lead_time_days' => $item->lead_time_days,
                    'warranty' => $item->warranty,
                    'return_conditions' => $item->return_conditions,
                    'status' => $item->status->value,
                    'status_label' => $item->status->label(),
                    'status_tone' => $item->status->tone(),
                    'decision_note' => $item->decision_note,
                ])->all(),
            ],
            'supplier' => [
                'id' => $listing->supplier->public_id,
                'business_name' => $listing->supplier->business_name,
                'status_label' => $listing->supplier->status->label(),
            ],
            'options' => [
                'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['public_id', 'name'])
                    ->map(fn (Category $c) => ['value' => $c->public_id, 'label' => $c->name])->all(),
            ],
            'history' => $listing->statusHistory->map(fn ($change) => [
                'previous_status' => $change->previous_status?->label(),
                'new_status' => $change->new_status->label(),
                'changed_by' => $change->changedBy?->name,
                'changed_at' => $change->changed_at->toIso8601String(),
                'reason' => $change->reason,
                'public_note' => $change->public_note,
            ])->all(),
        ]);
    }

    public function requestCorrection(Request $request, SupplierProductListing $listing, RequestSupplierListingCorrection $action): RedirectResponse
    {
        Gate::authorize('review', $listing);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate([
            'feedback' => ['required', 'string', 'max:1000'],
            'internal_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $action->handle($listing, $reviewer->id, $validated['feedback'], $validated['internal_reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['feedback' => $e->getMessage()]);
        }

        return back()->with('success', 'Correction requested.');
    }

    public function decide(Request $request, SupplierProductListing $listing, DecideSupplierListing $action): RedirectResponse
    {
        Gate::authorize('decide', $listing);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'connect_product_id' => ['nullable', 'string'],
            'create_product' => ['nullable', 'boolean'],
            'sku' => ['nullable', 'string', 'max:100', 'required_if:create_product,true'],
            'category_id' => ['nullable', 'string'],
            'brand_id' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'string'],
            'items.*.decision' => ['required', Rule::in(['approve', 'reject', 'correction'])],
            'items.*.variant_id' => ['nullable', 'string'],

            // Entered in Taka; parsed into a Money instance below, at this
            // HTTP boundary (§36.1).
            'items.*.platform_rate_minor' => ['nullable', new DecimalAmountRule],
            'items.*.wholesale_enabled' => ['nullable', 'boolean'],
            'items.*.dropshipping_enabled' => ['nullable', 'boolean'],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['items'] = array_values(array_map(function (array $item) {
            if (isset($item['platform_rate_minor'])) {
                $item['platform_rate_minor'] = DecimalAmount::parse($item['platform_rate_minor']);
            }

            return $item;
        }, $validated['items']));

        $approves = array_any($validated['items'], fn (array $item) => $item['decision'] === 'approve');

        // Setting a Platform Rate is the confidential-pricing boundary: a
        // reviewer who may decide a listing but not price it cannot approve.
        if ($approves) {
            abort_unless(
                $reviewer->can(PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::Edit)),
                403,
            );
        }

        try {
            $action->handle(
                $listing,
                $reviewer,
                [
                    'connect_product_id' => $validated['connect_product_id'] ?? null,
                    'create_product' => (bool) ($validated['create_product'] ?? false),
                    'sku' => $validated['sku'] ?? null,
                    'category_id' => $validated['category_id'] ?? null,
                    'brand_id' => $validated['brand_id'] ?? null,
                ],
                $validated['items'],
                $validated['reason'],
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return to_route('admin.supplier-listings.index')->with('success', 'Decision recorded.');
    }
}
