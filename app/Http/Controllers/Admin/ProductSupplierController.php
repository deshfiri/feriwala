<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Sourcing\Actions\ManageProductSuppliers;
use App\Domain\Sourcing\Models\ProductSupplierAssignment;
use App\Domain\Sourcing\Policies\ProductSupplierPolicy;
use App\Domain\Sourcing\Queries\ProductSuppliersSection;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Assigning Suppliers to Products, from the Admin Product workspace and the
 * Supplier page. Thin: identifies the actor, validates, and hands the decision
 * to {@see ManageProductSuppliers}. Browser requests carry public ids only.
 */
class ProductSupplierController extends Controller
{
    protected const PAGE_SIZE = 25;

    public function __construct(
        protected ManageProductSuppliers $suppliers,
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
     * Assign one Supplier to one or more Products — the Product workspace sends
     * one Product and many Suppliers, the Supplier page one Supplier and many
     * Products.
     */
    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(ProductSupplierPolicy::canAssign($actor), 403);

        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['string', 'distinct'],
            'supplier_ids' => ['required', 'array', 'min:1', 'max:100'],
            'supplier_ids.*' => ['string', 'distinct'],
            'reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $products = Product::query()->whereIn('public_id', $validated['product_ids'])->get();
        $suppliers = Supplier::query()->whereIn('public_id', $validated['supplier_ids'])->get();

        if ($products->count() !== count($validated['product_ids'])) {
            throw ValidationException::withMessages(['product_ids' => __('product_suppliers.errors.product_not_found')]);
        }

        if ($suppliers->count() !== count($validated['supplier_ids'])) {
            throw ValidationException::withMessages(['supplier_ids' => __('product_suppliers.errors.supplier_not_found')]);
        }

        if ($suppliers->contains(fn (Supplier $supplier) => $supplier->status !== SupplierStatus::Approved)) {
            throw ValidationException::withMessages(['supplier_ids' => __('product_suppliers.errors.supplier_not_approved')]);
        }

        $assigned = 0;

        foreach ($products as $product) {
            foreach ($suppliers as $supplier) {
                try {
                    $this->suppliers->assign($actor, $product, $supplier, $validated['reference'] ?? null, $validated['reason'] ?? null);
                    $assigned++;
                } catch (InvalidArgumentException) {
                    // Already assigned: nothing to do for that pair.
                }
            }
        }

        if ($assigned === 0) {
            throw ValidationException::withMessages(['supplier_ids' => __('product_suppliers.errors.already_assigned')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('product_suppliers.assigned', $assigned, ['count' => $assigned])]);

        return back();
    }

    public function destroy(Request $request, string $assignment): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(ProductSupplierPolicy::canRemove($actor), 403);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $record = ProductSupplierAssignment::query()->active()->where('public_id', $assignment)->firstOrFail();

        try {
            $this->suppliers->remove($actor, $record, $validated['reason'] ?? null);
        } catch (InvalidArgumentException $refused) {
            throw ValidationException::withMessages(['assignment' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('product_suppliers.removed')]);

        return back();
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
