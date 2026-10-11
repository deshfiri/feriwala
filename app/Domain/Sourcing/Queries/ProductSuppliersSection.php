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
 * Together the two sides answer "which Suppliers actually have this item":
 * a Product lists its Suppliers, a Supplier lists its Products.
 *
 * Staff-only, and null for anyone who may not view assignments.
 */
class ProductSuppliersSection
{
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

        return [
            'assigned' => $direct->map(fn (ProductSupplierAssignment $assignment) => [
                'id' => $assignment->public_id,
                'supplier' => $this->supplier($assignment->supplier),
                'reference' => $assignment->supplier_reference,
                'assigned_by' => $assignment->assigner->name,
                'assigned_at' => $assignment->assigned_at->toIso8601String(),
                'reason' => $assignment->assign_reason,
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
