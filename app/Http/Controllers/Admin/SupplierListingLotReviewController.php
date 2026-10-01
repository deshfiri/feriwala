<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Catalog\Models\Category;
use App\Domain\Supplier\Actions\DecideSupplierListingLot;
use App\Domain\Supplier\Actions\MatchSupplierListingItemToVariant;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingItem;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use App\Domain\Supplier\Policies\SupplierProductListingLotPolicy;
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
 * Staff review of a Supplier's listing lot (Supplier Bulk Product Listing
 * batch) -- one screen over the same per-entry decision
 * {@see SupplierListingLotController} already makes for a single listing,
 * gated by the identical `supplier_listing.*` permissions
 * ({@see SupplierProductListingLotPolicy}).
 *
 * Deciding a lot is deciding each of its entries independently
 * ({@see DecideSupplierListingLot}) -- a reviewer may approve one product
 * and leave another pending for a later round in the same request.
 */
class SupplierListingLotReviewController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SupplierProductListingLot::class);

        $status = $request->string('status')->toString();

        $lots = SupplierProductListingLot::query()
            ->with('supplier:id,public_id,business_name')
            ->withCount('items')
            ->when(LotStatus::tryFrom($status) !== null, fn ($q) => $q->where('status', $status))
            ->where('status', '!=', LotStatus::Draft->value)
            ->orderBy('submitted_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SupplierProductListingLot $lot) => [
                'id' => $lot->public_id,
                'reference' => $lot->reference,
                'title' => $lot->title,
                'supplier' => $lot->supplier->business_name,
                'items_count' => $lot->items_count,
                'status' => $lot->status->value,
                'status_label' => $lot->status->label(),
                'status_tone' => $lot->status->tone(),
                'submitted_at' => $lot->submitted_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/supplier-listing-lots/index', [
            'lots' => $lots,
            'statuses' => array_map(fn (LotStatus $s) => ['value' => $s->value, 'label' => $s->label()], LotStatus::cases()),
        ]);
    }

    public function show(Request $request, SupplierProductListingLot $lot): Response
    {
        Gate::authorize('view', $lot);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $mayViewPricing = $reviewer->can(PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::View));

        $lot->load(['supplier', 'items.category', 'items.brand', 'items.items.attributeValues', 'items.media']);

        return Inertia::render('admin/supplier-listing-lots/show', [
            'lot' => [
                'id' => $lot->public_id,
                'reference' => $lot->reference,
                'title' => $lot->title,
                'status' => $lot->status->value,
                'status_label' => $lot->status->label(),
                'status_tone' => $lot->status->tone(),
                'awaits_decision' => $lot->status === LotStatus::UnderReview,
                'can_decide' => Gate::allows('decide', $lot),
                'may_view_pricing' => $mayViewPricing,
                'entries' => $lot->items->map(fn (SupplierProductListing $entry) => [
                    'id' => $entry->public_id,
                    'product_name' => $entry->product_name,
                    'description' => $entry->description,
                    'category' => $entry->category?->name,
                    'category_suggestion' => $entry->category_suggestion,
                    'brand' => $entry->brand?->name,
                    'brand_suggestion' => $entry->brand_suggestion,
                    'supplier_note' => $entry->supplier_note,
                    'status' => $entry->status->value,
                    'status_label' => $entry->status->label(),
                    'status_tone' => $entry->status->tone(),
                    'connected_product' => $entry->connectedProduct?->public_id,
                    'primary_media_url' => $entry->primaryMedia() !== null
                        ? route('supplier.listings.media.download', $entry->primaryMedia()->public_id)
                        : null,
                    'items' => $entry->items->map(fn (SupplierProductListingItem $item) => [
                        'id' => $item->public_id,
                        'variant_label' => $item->variant_label,
                        'supplier_sku' => $item->supplier_sku,
                        'supplier_rate' => $mayViewPricing ? $item->supplier_rate->jsonSerialize() : null,
                        'supply_mode' => $item->supply_mode->value,
                        'supply_mode_label' => $item->supply_mode->label(),
                        'available_quantity' => $item->available_quantity,
                        'fulfilment_capacity' => $item->fulfilment_capacity,
                        'lead_time_days' => $item->lead_time_days,
                        'expected_availability_at' => $item->expected_availability_at?->toIso8601String(),
                        'status' => $item->status->value,
                        'status_label' => $item->status->label(),
                        'decision_note' => $item->decision_note,
                        'suggested_variants' => $entry->connectedProduct !== null
                            ? app(MatchSupplierListingItemToVariant::class)->handle($entry->connectedProduct, $item)
                                ->map(fn ($variant) => ['id' => $variant->public_id, 'label' => $variant->sku])->all()
                            : [],
                    ])->all(),
                ])->all(),
            ],
            'supplier' => [
                'id' => $lot->supplier->public_id,
                'business_name' => $lot->supplier->business_name,
            ],
            'options' => [
                'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['public_id', 'name'])
                    ->map(fn (Category $c) => ['value' => $c->public_id, 'label' => $c->name])->all(),
            ],
        ]);
    }

    public function decide(Request $request, SupplierProductListingLot $lot, DecideSupplierListingLot $action): RedirectResponse
    {
        Gate::authorize('decide', $lot);

        /** @var User $reviewer */
        $reviewer = $request->user();

        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.listing_id' => ['required', 'string'],
            'entries.*.reason' => ['required', 'string', 'max:1000'],
            'entries.*.connect_product_id' => ['nullable', 'string'],
            'entries.*.create_product' => ['nullable', 'boolean'],
            'entries.*.sku' => ['nullable', 'string', 'max:100', 'required_if:entries.*.create_product,true'],
            'entries.*.category_id' => ['nullable', 'string'],
            'entries.*.brand_id' => ['nullable', 'string'],
            'entries.*.items' => ['required', 'array', 'min:1'],
            'entries.*.items.*.item_id' => ['required', 'string'],
            'entries.*.items.*.decision' => ['required', Rule::in(['approve', 'reject', 'correction'])],
            'entries.*.items.*.variant_id' => ['nullable', 'string'],

            // Entered in Taka; parsed into a Money instance below, at this
            // HTTP boundary (§36.1).
            'entries.*.items.*.platform_rate' => ['nullable', new DecimalAmountRule],
            'entries.*.items.*.approved_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'entries.*.items.*.wholesale_enabled' => ['nullable', 'boolean'],
            'entries.*.items.*.dropshipping_enabled' => ['nullable', 'boolean'],
            'entries.*.items.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $approvesAny = false;

        $decisions = array_values(array_map(function (array $entry) use (&$approvesAny) {
            $items = array_values(array_map(function (array $item) use (&$approvesAny) {
                if (isset($item['platform_rate'])) {
                    $item['platform_rate'] = DecimalAmount::parse($item['platform_rate']);
                }

                if ($item['decision'] === 'approve') {
                    $approvesAny = true;
                }

                return $item;
            }, $entry['items']));

            return [
                'listing_id' => $entry['listing_id'],
                'reason' => $entry['reason'],
                'product' => [
                    'connect_product_id' => $entry['connect_product_id'] ?? null,
                    'create_product' => (bool) ($entry['create_product'] ?? false),
                    'sku' => $entry['sku'] ?? null,
                    'category_id' => $entry['category_id'] ?? null,
                    'brand_id' => $entry['brand_id'] ?? null,
                ],
                'items' => $items,
            ];
        }, $validated['entries']));

        // Setting a Platform Rate is the confidential-pricing boundary: a
        // reviewer who may decide a lot but not price it cannot approve any
        // of its entries (mirrors SupplierListingController::decide()).
        if ($approvesAny) {
            abort_unless(
                $reviewer->can(PermissionCatalogue::name(PermissionModule::SupplierPricing, PermissionAction::Edit)),
                403,
            );
        }

        try {
            $results = $action->handle($lot, $reviewer, $decisions);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lot' => $e->getMessage()]);
        }

        // Each entry is decided independently ({@see DecideSupplierListingLot}
        // docblock) -- a bad entry is reported and skipped, never a reason to
        // discard decisions that already succeeded beside it. Same shape as
        // SupplierPayableController::bulkSettle()'s own flash.
        return back()->with('bulk_result', [
            'succeeded' => count(array_filter($results, fn (array $result) => $result['ok'])),
            'refused' => array_values(array_map(
                fn (array $result) => ['reference' => $result['listing_id'], 'reason' => $result['message']],
                array_filter($results, fn (array $result) => ! $result['ok']),
            )),
        ]);
    }
}
