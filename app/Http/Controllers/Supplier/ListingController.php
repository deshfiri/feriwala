<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Supplier\Actions\ArchiveSupplierListingDraft;
use App\Domain\Supplier\Actions\SaveSupplierListingDraft;
use App\Domain\Supplier\Actions\SubmitSupplierListing;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingItem;
use App\Http\Controllers\Controller;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * A Supplier's own Product Listing Requests (D25, P13-9, P13-11).
 *
 * Every lookup is scoped through the authenticated Supplier's own `listings()`
 * relation — never a bare route-bound model — so there is no way for one
 * Supplier to reach another's request by guessing or editing a URL
 * (§31.3-equivalent). Submitting one never writes to `products`; it is
 * always a proposal until staff decides it.
 */
class ListingController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $status = $request->string('status')->toString();

        $listings = $supplier->listings()
            ->when(
                ListingStatus::tryFrom($status) !== null,
                fn ($query) => $query->where('status', $status),
            )
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (SupplierProductListing $listing) => [
                'id' => $listing->public_id,
                'reference' => $listing->reference,
                'product_name' => $listing->product_name,
                'status' => $listing->status->value,
                'status_label' => $listing->status->label(),
                'status_tone' => $listing->status->tone(),
                'submitted_at' => $listing->submitted_at?->toIso8601String(),
                'updated_at' => $listing->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('supplier/listings/index', ['listings' => $listings]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('supplier/listings/form', [
            'listing' => null,
            'options' => $this->options(),
        ]);
    }

    public function store(Request $request, SaveSupplierListingDraft $save): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        [$attributes, $items] = $this->validated($request);

        $listing = $save->handle($supplier, $attributes, $items);

        return to_route('supplier.listings.show', $listing->public_id)->with('success', 'Draft saved.');
    }

    public function show(Request $request, string $listing): Response
    {
        $model = $this->ownListing($request, $listing);
        $model->load(['items', 'statusHistory' => fn ($q) => $q->orderByDesc('id'), 'category', 'brand']);

        return Inertia::render('supplier/listings/show', [
            'listing' => $this->present($model),
        ]);
    }

    public function edit(Request $request, string $listing): Response
    {
        $model = $this->ownListing($request, $listing);

        abort_unless($model->isEditableBySupplier(), 403);

        $model->load('items');

        return Inertia::render('supplier/listings/form', [
            'listing' => $this->present($model),
            'options' => $this->options(),
        ]);
    }

    public function update(Request $request, string $listing, SaveSupplierListingDraft $save): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');
        $model = $this->ownListing($request, $listing);

        [$attributes, $items] = $this->validated($request);

        try {
            $save->handle($supplier, $attributes, $items, $model);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['listing' => $e->getMessage()]);
        }

        return to_route('supplier.listings.show', $model->public_id)->with('success', 'Listing updated.');
    }

    public function submit(Request $request, string $listing, SubmitSupplierListing $submit): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');
        $model = $this->ownListing($request, $listing);

        try {
            $submit->handle($supplier, $model);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['listing' => $e->getMessage()]);
        }

        return to_route('supplier.listings.index')->with('success', 'Listing submitted for review.');
    }

    public function archive(Request $request, string $listing, ArchiveSupplierListingDraft $archive): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');
        $model = $this->ownListing($request, $listing);

        try {
            $archive->handle($supplier, $model);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['listing' => $e->getMessage()]);
        }

        return to_route('supplier.listings.index')->with('success', 'Draft archived.');
    }

    protected function ownListing(Request $request, string $publicId): SupplierProductListing
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        return $supplier->listings()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'string'],
            'category_suggestion' => ['nullable', 'string', 'max:255'],
            'brand_id' => ['nullable', 'string'],
            'brand_suggestion' => ['nullable', 'string', 'max:255'],
            'supplier_note' => ['nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array'],
            'documents' => ['nullable', 'array'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'string'],
            'items.*.variant_label' => ['nullable', 'string', 'max:255'],
            'items.*.supplier_sku' => ['required', 'string', 'max:100'],

            // Entered in Taka; parsed into a Money instance below, at this
            // HTTP boundary (§36.1).
            'items.*.supplier_rate_minor' => ['required', new DecimalAmountRule],
            'items.*.currency_code' => ['nullable', 'string', 'size:3'],
            'items.*.available_quantity' => ['required', 'integer', 'min:0'],
            'items.*.minimum_supply_quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.lead_time_days' => ['nullable', 'integer', 'min:0'],
            'items.*.warranty' => ['nullable', 'string', 'max:255'],
            'items.*.return_conditions' => ['nullable', 'string', 'max:1000'],
        ]);

        $items = array_values(array_map(function (array $item) {
            $currency = isset($item['currency_code'])
                ? Currency::from($item['currency_code'])
                : Currency::base();

            $item['supplier_rate_minor'] = DecimalAmount::parse($item['supplier_rate_minor'], $currency);

            return $item;
        }, $data['items']));

        unset($data['items']);

        return [$data, $items];
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(SupplierProductListing $listing): array
    {
        return [
            'id' => $listing->public_id,
            'reference' => $listing->reference,
            'product_name' => $listing->product_name,
            'description' => $listing->description,
            'category_id' => $listing->category?->public_id,
            'category_suggestion' => $listing->category_suggestion,
            'brand_id' => $listing->brand?->public_id,
            'brand_suggestion' => $listing->brand_suggestion,
            'supplier_note' => $listing->supplier_note,
            'images' => $listing->images,
            'documents' => $listing->documents,
            'status' => $listing->status->value,
            'status_label' => $listing->status->label(),
            'status_tone' => $listing->status->tone(),
            'is_editable' => $listing->isEditableBySupplier(),
            'submitted_at' => $listing->submitted_at?->toIso8601String(),
            'decision_note' => $listing->decision_note,
            'items' => $listing->items->map(fn (SupplierProductListingItem $item) => [
                'id' => $item->public_id,
                'variant_label' => $item->variant_label,
                'supplier_sku' => $item->supplier_sku,
                'supplier_rate' => $item->supplier_rate->jsonSerialize(),
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
            'status_history' => $listing->relationLoaded('statusHistory') ? $listing->statusHistory->map(fn ($change) => [
                'previous_status' => $change->previous_status?->label(),
                'new_status' => $change->new_status->label(),
                'reason' => $change->reason,
                'public_note' => $change->public_note,
                'changed_at' => $change->changed_at->toIso8601String(),
            ])->all() : [],
        ];
    }
}
