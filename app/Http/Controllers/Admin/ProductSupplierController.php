<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Policies\ProductSupplierPolicy;
use App\Domain\Sourcing\Queries\ProductSuppliersSection;
use App\Domain\Supplier\Actions\AssignProductToSupplier;
use App\Domain\Supplier\Actions\ChangeSupplierBasePrice;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Assigning Products to Suppliers from the Admin Product workspace.
 *
 * Thin: identifies the actor, validates, and hands the decision to
 * {@see AssignProductToSupplier} / {@see ChangeSupplierBasePrice}. Browser
 * requests carry public ids only, and the Supplier Base Price arrives as the
 * decimal Taka the person typed.
 */
class ProductSupplierController extends Controller
{
    protected const PAGE_SIZE = 25;

    public function __construct(
        protected AssignProductToSupplier $assign,
        protected ChangeSupplierBasePrice $changePrice,
        protected ProductSuppliersSection $section,
    ) {}

    /**
     * Approved Suppliers, page by page, for the right-hand picker. Searches
     * business name, reference, email and mobile.
     */
    public function browse(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        abort_unless(ProductSupplierPolicy::canAssign($actor), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $term = trim((string) ($validated['q'] ?? ''));

        $page = Supplier::query()
            ->where('status', SupplierStatus::Approved)
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('business_name', 'ilike', "%{$term}%")
                ->orWhere('reference', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%")
                ->orWhere('mobile', 'ilike', "%{$term}%")))
            ->orderBy('business_name')
            ->orderBy('id')
            ->offset((((int) ($validated['page'] ?? 1)) - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        return response()->json([
            'data' => $page->take(self::PAGE_SIZE)->map(fn (Supplier $supplier) => $this->section->supplier($supplier))->values()->all(),
            'has_more' => $page->count() > self::PAGE_SIZE,
        ]);
    }

    /**
     * Assign one Product (or one variation) to one Supplier at a Supplier Base
     * Price. Everything else about the offer is optional.
     */
    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(ProductSupplierPolicy::canAssign($actor), 403);

        $validated = $request->validate([
            'product_id' => ['required', 'string'],
            'supplier_id' => ['required', 'string'],
            'variant_id' => ['nullable', 'string'],
            'base_price' => ['required', new DecimalAmountRule],
            'supply_mode' => ['nullable', Rule::enum(SupplyMode::class)],
            'quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'fulfilment_capacity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $product = Product::query()->where('public_id', $validated['product_id'])->first()
            ?? throw ValidationException::withMessages(['product_id' => __('product_suppliers.errors.product_not_found')]);

        $supplier = Supplier::query()->where('public_id', $validated['supplier_id'])->first()
            ?? throw ValidationException::withMessages(['supplier_id' => __('product_suppliers.errors.supplier_not_found')]);

        $variant = blank($validated['variant_id'] ?? null)
            ? null
            : (ProductVariant::query()->where('public_id', $validated['variant_id'])->where('product_id', $product->id)->first()
                ?? throw ValidationException::withMessages(['variant_id' => __('product_suppliers.errors.variant_not_found')]));

        $basePrice = DecimalAmount::parse($validated['base_price'], Currency::BDT);

        try {
            $this->assign->handle(
                $actor,
                $product,
                $variant,
                $supplier,
                $basePrice,
                [
                    'supply_mode' => isset($validated['supply_mode']) ? SupplyMode::from($validated['supply_mode']) : null,
                    'quantity' => $validated['quantity'] ?? null,
                    'fulfilment_capacity' => $validated['fulfilment_capacity'] ?? null,
                    'lead_time_days' => $validated['lead_time_days'] ?? null,
                    'reason' => $validated['reason'] ?? null,
                ],
            );
        } catch (InvalidArgumentException $refused) {
            throw ValidationException::withMessages(['assignment' => $refused->getMessage()]);
        }

        $message = __('product_suppliers.assigned', ['name' => $supplier->business_name]);

        if ($this->assign->hasNoMargin($product, $variant, $basePrice)) {
            $message .= ' '.__('product_suppliers.no_margin_warning');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * Change only the Supplier Base Price of an offer; always with a reason.
     */
    public function updatePrice(Request $request, string $offer): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(ProductSupplierPolicy::canEditOffer($actor), 403);

        $validated = $request->validate([
            'base_price' => ['required', new DecimalAmountRule],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $record = SupplierOffer::query()->where('public_id', $offer)->with(['product', 'variant'])->firstOrFail();
        $basePrice = DecimalAmount::parse($validated['base_price'], Currency::BDT);

        try {
            $this->changePrice->handle($actor, $record, $basePrice, $validated['reason']);
        } catch (InvalidArgumentException $refused) {
            throw ValidationException::withMessages(['base_price' => $refused->getMessage()]);
        }

        $message = __('product_suppliers.price_changed');

        if ($this->assign->hasNoMargin($record->product, $record->variant, $basePrice)) {
            $message .= ' '.__('product_suppliers.no_margin_warning');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
