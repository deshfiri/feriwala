<?php

namespace App\Domain\Sourcing\Queries;

use App\Domain\Catalog\Models\Product;
use App\Domain\Sourcing\Models\ProductSupplierAssignment;
use App\Domain\Sourcing\Policies\ProductSupplierPolicy;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;

/**
 * What the "Suppliers" section of the Admin Product workspace and the
 * "Assigned products" section of the Supplier page show.
 *
 * The Product side lists the Suppliers assigned to the Product itself, then —
 * as a reference — the Suppliers assigned to Products in its Same Product group,
 * each labelled with the Product it was assigned to. The two are never merged:
 * only a direct assignment can be removed here.
 *
 * Staff-only, and null for anyone who may not view assignments.
 */
class ProductSuppliersSection
{
    public function __construct(protected ResolveProductNetwork $network) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forProduct(Product $product, User $viewer): ?array
    {
        if (! ProductSupplierPolicy::canView($viewer)) {
            return null;
        }

        $direct = ProductSupplierAssignment::query()->active()
            ->with(['supplier', 'assigner:id,name'])
            ->where('product_id', $product->id)
            ->orderBy('assigned_at')
            ->get();

        $assignedSupplierIds = $direct->pluck('supplier_id')->all();

        $networkIds = array_keys($this->network->network($product->id));

        $viaGroup = $networkIds === [] ? collect() : ProductSupplierAssignment::query()->active()
            ->with(['supplier', 'product:id,public_id,name,sku'])
            ->whereIn('product_id', $networkIds)
            ->whereNotIn('supplier_id', $assignedSupplierIds)
            ->orderBy('assigned_at')
            ->get();

        return [
            'assigned' => $direct->map(fn (ProductSupplierAssignment $assignment) => [
                'id' => $assignment->public_id,
                'supplier' => $this->supplier($assignment->supplier),
                'reference' => $assignment->supplier_reference,
                'assigned_by' => $assignment->assigner->name,
                'assigned_at' => $assignment->assigned_at->toIso8601String(),
                'reason' => $assignment->assign_reason,
            ])->values()->all(),
            // One row per Supplier, however many group Products it holds.
            'via_group' => $viaGroup->groupBy('supplier_id')->map(fn ($rows) => [
                'supplier' => $this->supplier($rows->first()->supplier),
                'products' => $rows->map(fn (ProductSupplierAssignment $assignment) => [
                    'id' => $assignment->product->public_id,
                    'name' => $assignment->product->name,
                    'bpc' => $assignment->product->sku,
                ])->values()->all(),
            ])->values()->all(),
            'can' => [
                'assign' => ProductSupplierPolicy::canAssign($viewer),
                'remove' => ProductSupplierPolicy::canRemove($viewer),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forSupplier(Supplier $supplier, User $viewer): ?array
    {
        if (! ProductSupplierPolicy::canView($viewer)) {
            return null;
        }

        $assignments = ProductSupplierAssignment::query()->active()
            ->with('product:id,public_id,name,sku,status')
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('assigned_at')
            ->get();

        return [
            'assigned' => $assignments->map(fn (ProductSupplierAssignment $assignment) => [
                'id' => $assignment->public_id,
                'product' => [
                    'id' => $assignment->product->public_id,
                    'name' => $assignment->product->name,
                    'bpc' => $assignment->product->sku,
                    'sku' => $assignment->product->sku,
                ],
                'reference' => $assignment->supplier_reference,
                'assigned_at' => $assignment->assigned_at->toIso8601String(),
            ])->values()->all(),
            'can' => [
                'assign' => ProductSupplierPolicy::canAssign($viewer),
                'remove' => ProductSupplierPolicy::canRemove($viewer),
            ],
        ];
    }

    /**
     * @return array{id: string, reference: string, name: string, status: string, status_label: string, status_tone: string}
     */
    public function supplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->public_id,
            'reference' => $supplier->reference,
            'name' => $supplier->business_name,
            'status' => $supplier->status->value,
            'status_label' => $supplier->status->label(),
            'status_tone' => $supplier->status->tone(),
        ];
    }
}
