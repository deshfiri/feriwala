<?php

namespace App\Domain\Sourcing\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Models\Product;
use App\Domain\Sourcing\Enums\SourcingMappingStatus;
use App\Domain\Sourcing\Models\ProductSupplierAssignment;
use App\Domain\Sourcing\Policies\ProductSupplierPolicy;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Recording which Suppliers actually have a Product.
 *
 * A fact, not a price: assigning changes no rate, offer or listing, and the
 * Supplier listing system stays the only place those are set. Assigning twice
 * is refused, removing keeps the row as history, and every change is audited.
 */
class ManageProductSuppliers
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException when the Supplier already has this Product
     */
    public function assign(User $actor, Product $product, Supplier $supplier, ?string $supplierReference = null, ?string $reason = null): ProductSupplierAssignment
    {
        ProductSupplierPolicy::authorize(ProductSupplierPolicy::canAssign($actor), 'You may not assign Suppliers to Products.');

        return $this->database->transaction(function () use ($actor, $product, $supplier, $supplierReference, $reason) {
            // One place to queue on, so two people assigning the same pair
            // cannot both pass the duplicate check.
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $exists = ProductSupplierAssignment::query()->active()
                ->where('product_id', $product->id)
                ->where('supplier_id', $supplier->id)
                ->exists();

            if ($exists) {
                throw new InvalidArgumentException("{$supplier->business_name} is already assigned to {$product->name}.");
            }

            $assignment = ProductSupplierAssignment::create([
                'product_id' => $product->id,
                'supplier_id' => $supplier->id,
                'status' => SourcingMappingStatus::Active,
                'supplier_reference' => $this->clean($supplierReference),
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'assign_reason' => $this->clean($reason),
            ]);

            $this->record('product_supplier.assigned', $assignment, $actor, after: [
                'product' => $product->public_id,
                'supplier' => $supplier->public_id,
            ], reason: $reason);

            return $assignment;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException when it was already removed
     */
    public function remove(User $actor, ProductSupplierAssignment $assignment, ?string $reason = null): void
    {
        ProductSupplierPolicy::authorize(ProductSupplierPolicy::canRemove($actor), 'You may not remove Supplier assignments.');

        $this->database->transaction(function () use ($actor, $assignment, $reason) {
            /** @var ProductSupplierAssignment $locked */
            $locked = ProductSupplierAssignment::query()->with(['product', 'supplier'])->lockForUpdate()->findOrFail($assignment->id);

            if ($locked->status !== SourcingMappingStatus::Active) {
                throw new InvalidArgumentException('That assignment was already removed.');
            }

            $locked->forceFill([
                'status' => SourcingMappingStatus::Removed,
                'removed_by' => $actor->id,
                'removed_at' => now(),
                'removal_reason' => $this->clean($reason),
            ])->save();

            $this->record('product_supplier.removed', $locked, $actor, before: [
                'product' => $locked->product->public_id,
                'supplier' => $locked->supplier->public_id,
            ], reason: $reason);
        });
    }

    protected function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<array-key, mixed>|null  $before
     * @param  array<array-key, mixed>|null  $after
     */
    protected function record(string $action, ProductSupplierAssignment $assignment, User $actor, ?array $before = null, ?array $after = null, ?string $reason = null): void
    {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: ProductSupplierAssignment::class,
            auditableId: $assignment->id,
            before: $before,
            after: $after,
            reason: $this->clean($reason),
            module: PermissionModule::ProductSupplier->value,
        ));
    }
}
