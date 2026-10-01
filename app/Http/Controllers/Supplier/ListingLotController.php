<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Supplier\Actions\ArchiveSupplierListingLotDraft;
use App\Domain\Supplier\Actions\CreateSupplierListingLot;
use App\Domain\Supplier\Actions\SubmitSupplierListingLot;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingItem;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * A Supplier's own multi-product listing batches (Supplier Bulk Product
 * Listing batch).
 *
 * Every lookup is scoped through the authenticated Supplier's own `lots()`
 * relation, exactly as {@see ListingController} already does for a single
 * listing — never a bare route-bound model (§31.3-equivalent). Product
 * entries themselves are added, edited and removed through
 * {@see ListingLotItemController}; this controller only manages the lot as a
 * whole.
 */
class ListingLotController extends Controller
{
    public function index(Request $request): Response
    {
        $supplier = $this->supplier($request);

        $status = $request->string('status')->toString();

        $lots = $supplier->lots()
            ->when(
                LotStatus::tryFrom($status) !== null,
                fn ($query) => $query->where('status', $status),
            )
            ->withCount('items')
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (SupplierProductListingLot $lot) => [
                'id' => $lot->public_id,
                'reference' => $lot->reference,
                'title' => $lot->title,
                'status' => $lot->status->value,
                'status_label' => $lot->status->label(),
                'status_tone' => $lot->status->tone(),
                'item_count' => $lot->items_count,
                'submitted_at' => $lot->submitted_at?->toIso8601String(),
                'updated_at' => $lot->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('supplier/listing-lots/index', ['lots' => $lots]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('supplier/listing-lots/create');
    }

    public function store(Request $request, CreateSupplierListingLot $create): RedirectResponse
    {
        $supplier = $this->supplier($request);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $lot = $create->handle($supplier, $data['title'] ?? null);

        return to_route('supplier.listing-lots.show', $lot->public_id);
    }

    public function show(Request $request, string $lot): Response
    {
        $model = $this->ownLot($request, $lot);
        $model->load([
            'items' => fn ($query) => $query->with(['items.attributeValues', 'media', 'category', 'brand']),
            'statusHistory' => fn ($query) => $query->orderByDesc('id'),
        ]);

        return Inertia::render('supplier/listing-lots/workspace', [
            'lot' => $this->present($model),
            'options' => $this->options(),
        ]);
    }

    public function submit(Request $request, string $lot, SubmitSupplierListingLot $submit): RedirectResponse
    {
        $supplier = $this->supplier($request);
        $model = $this->ownLot($request, $lot);

        try {
            $submit->handle($supplier, $model);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lot' => $e->getMessage()]);
        }

        return to_route('supplier.listing-lots.show', $model->public_id)->with('success', 'Batch submitted for review.');
    }

    public function archive(Request $request, string $lot, ArchiveSupplierListingLotDraft $archive): RedirectResponse
    {
        $supplier = $this->supplier($request);
        $model = $this->ownLot($request, $lot);

        try {
            $archive->handle($supplier, $model);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lot' => $e->getMessage()]);
        }

        return to_route('supplier.listing-lots.index')->with('success', 'Draft batch discarded.');
    }

    protected function supplier(Request $request): Supplier
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        return $supplier;
    }

    protected function ownLot(Request $request, string $publicId): SupplierProductListingLot
    {
        return $this->supplier($request)->lots()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        return [
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['public_id', 'name'])
                ->map(fn (Category $c) => ['value' => $c->public_id, 'label' => $c->name])->all(),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(['public_id', 'name'])
                ->map(fn (Brand $b) => ['value' => $b->public_id, 'label' => $b->name])->all(),
            // A Supplier only ever selects a value already in the catalogue
            // (never creates one) -- see ManageSupplierListingItemAttributes.
            'attributes' => ProductAttribute::query()->orderBy('sort_order')->with('values')->get()
                ->map(fn (ProductAttribute $attribute) => [
                    'id' => $attribute->public_id,
                    'name' => $attribute->name,
                    'values' => $attribute->values->map(fn (ProductAttributeValue $value) => [
                        'value' => $value->public_id,
                        'label' => $value->value,
                    ])->all(),
                ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(SupplierProductListingLot $lot): array
    {
        return [
            'id' => $lot->public_id,
            'reference' => $lot->reference,
            'title' => $lot->title,
            'status' => $lot->status->value,
            'status_label' => $lot->status->label(),
            'status_tone' => $lot->status->tone(),
            'is_draft' => $lot->status === LotStatus::Draft,
            'submitted_at' => $lot->submitted_at?->toIso8601String(),
            'entries' => $lot->items->map(fn (SupplierProductListing $entry) => $this->presentEntry($entry))->all(),
            'status_history' => $lot->relationLoaded('statusHistory') ? $lot->statusHistory->map(fn ($change) => [
                'previous_status' => $change->previous_status?->label(),
                'new_status' => $change->new_status->label(),
                'reason' => $change->reason,
                'public_note' => $change->public_note,
                'changed_at' => $change->changed_at->toIso8601String(),
            ])->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentEntry(SupplierProductListing $entry): array
    {
        return [
            'id' => $entry->public_id,
            'product_name' => $entry->product_name,
            'description' => $entry->description,
            'category_id' => $entry->category?->public_id,
            'category_suggestion' => $entry->category_suggestion,
            'brand_id' => $entry->brand?->public_id,
            'brand_suggestion' => $entry->brand_suggestion,
            'supplier_note' => $entry->supplier_note,
            'status' => $entry->status->value,
            'status_label' => $entry->status->label(),
            'status_tone' => $entry->status->tone(),
            'is_editable' => $entry->isEditableBySupplier(),
            'decision_note' => $entry->decision_note,
            'primary_media_id' => $entry->primaryMedia()?->public_id,
            'media' => $entry->media->map(fn ($media) => [
                'id' => $media->public_id,
                'role' => $media->role,
                'alt_text' => $media->alt_text,
                'download_url' => route('supplier.listings.media.download', $media->public_id),
            ])->all(),
            'items' => $entry->items->map(fn (SupplierProductListingItem $item) => [
                'id' => $item->public_id,
                'variant_label' => $item->variant_label,
                'supplier_sku' => $item->supplier_sku,
                'supplier_rate' => $item->supplier_rate->jsonSerialize(),
                'available_quantity' => $item->available_quantity,
                'minimum_supply_quantity' => $item->minimum_supply_quantity,
                'lead_time_days' => $item->lead_time_days,
                'warranty' => $item->warranty,
                'return_conditions' => $item->return_conditions,
                'supply_mode' => $item->supply_mode->value,
                'supply_mode_label' => $item->supply_mode->label(),
                'fulfilment_capacity' => $item->fulfilment_capacity,
                'expected_availability_at' => $item->expected_availability_at?->toIso8601String(),
                'status' => $item->status->value,
                'status_label' => $item->status->label(),
                'attribute_value_ids' => $item->attributeValues->pluck('public_id')->all(),
                'decision_note' => $item->decision_note,
            ])->all(),
        ];
    }
}
