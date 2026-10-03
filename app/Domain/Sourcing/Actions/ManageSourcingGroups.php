<?php

namespace App\Domain\Sourcing\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Sourcing\Enums\SourcingMappingStatus;
use App\Domain\Sourcing\Exceptions\SourcingGroupRefused;
use App\Domain\Sourcing\Models\ProductSourcingGroup;
use App\Domain\Sourcing\Models\ProductSourcingGroupProduct;
use App\Domain\Sourcing\Models\ProductSourcingVariantMapping;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * Every change to a Product Sourcing Group goes through here, so the rules and
 * the audit trail live in one place.
 *
 * Two things hold throughout:
 *
 * - **Nothing is deleted.** A removal records who, when and why; the row stays
 *   as the history that explains an earlier allocation.
 * - **Nothing silently corrupts open work.** A product or variation mapping
 *   cannot be removed while an active allocation relies on it.
 *
 * Every audit entry is filed against the group itself, so the group's history
 * reads as one timeline: creation, edits, activation, each mapping change.
 */
class ManageSourcingGroups
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array{code: string, name_en: string, name_bn: string, description?: string|null}  $attributes
     */
    public function create(User $actor, array $attributes): ProductSourcingGroup
    {
        return $this->database->transaction(function () use ($actor, $attributes) {
            $group = ProductSourcingGroup::create([
                'code' => $attributes['code'],
                'name_en' => $attributes['name_en'],
                'name_bn' => $attributes['name_bn'],
                'description' => $attributes['description'] ?? null,
                'is_active' => true,
                'created_by' => $actor->id,
            ]);

            $this->record('sourcing_group.created', $group, $actor, after: [
                'code' => $group->code, 'name_en' => $group->name_en, 'name_bn' => $group->name_bn,
            ]);

            return $group;
        });
    }

    /**
     * @param  array{name_en?: string, name_bn?: string, description?: string|null}  $attributes
     */
    public function update(User $actor, ProductSourcingGroup $group, array $attributes): ProductSourcingGroup
    {
        return $this->database->transaction(function () use ($actor, $group, $attributes) {
            $group->fill($attributes);
            $group->updated_by = $actor->id;

            $before = array_intersect_key($group->getOriginal(), $group->getChanges());
            $after = $group->getChanges();
            unset($before['updated_by'], $after['updated_by'], $before['updated_at'], $after['updated_at']);

            $group->save();

            if ($after !== []) {
                $this->record('sourcing_group.updated', $group, $actor, before: $before, after: $after);
            }

            return $group;
        });
    }

    public function setActive(User $actor, ProductSourcingGroup $group, bool $isActive, string $reason): ProductSourcingGroup
    {
        $this->requireReason($reason);

        return $this->database->transaction(function () use ($actor, $group, $isActive, $reason) {
            if ($group->is_active === $isActive) {
                return $group;
            }

            $group->forceFill(['is_active' => $isActive, 'updated_by' => $actor->id])->save();

            $this->record(
                $isActive ? 'sourcing_group.activated' : 'sourcing_group.deactivated',
                $group, $actor,
                before: ['is_active' => ! $isActive],
                after: ['is_active' => $isActive],
                reason: $reason,
            );

            return $group;
        });
    }

    /**
     * Put a catalogue product into the group.
     *
     * The first member becomes the canonical product -- the one whose
     * variations define what an order requires. Adding the canonical product
     * itself needs no variant mappings: it maps to itself.
     */
    public function addProduct(User $actor, ProductSourcingGroup $group, Product $product, string $reason): ProductSourcingGroupProduct
    {
        $this->requireReason($reason);

        return $this->database->transaction(function () use ($actor, $group, $product, $reason) {
            /** @var ProductSourcingGroup $locked */
            $locked = ProductSourcingGroup::query()->lockForUpdate()->findOrFail($group->id);

            if (! $locked->is_active) {
                throw SourcingGroupRefused::inactiveGroup();
            }

            $existing = ProductSourcingGroupProduct::query()
                ->active()
                ->where('product_id', $product->id)
                ->with('group')
                ->first();

            if ($existing !== null) {
                throw SourcingGroupRefused::alreadyInAGroup($product->name, $existing->group->name_en);
            }

            $isCanonical = ! $locked->products()->active()->where('is_canonical', true)->exists();

            $membership = $locked->products()->create([
                'product_id' => $product->id,
                'is_canonical' => $isCanonical,
                'status' => SourcingMappingStatus::Active,
                'added_by' => $actor->id,
                'added_reason' => $reason,
            ]);

            $this->record('sourcing_group.product_added', $locked, $actor, after: [
                'product' => $product->public_id, 'canonical' => $isCanonical,
            ], reason: $reason);

            return $membership;
        });
    }

    public function removeProduct(User $actor, ProductSourcingGroup $group, Product $product, string $reason): void
    {
        $this->requireReason($reason);

        $this->database->transaction(function () use ($actor, $group, $product, $reason) {
            ProductSourcingGroup::query()->lockForUpdate()->findOrFail($group->id);

            $membership = $group->products()->active()->where('product_id', $product->id)->first();

            if ($membership === null) {
                throw SourcingGroupRefused::notAMember();
            }

            if ($membership->is_canonical && $group->products()->active()->count() > 1) {
                throw SourcingGroupRefused::canonicalHasMembers();
            }

            if ($this->hasOpenAllocationOnProduct($product, null, $group->id)) {
                throw SourcingGroupRefused::inUseByOpenAllocation();
            }

            $now = now();

            $group->variantMappings()->active()->where('product_id', $product->id)->update([
                'status' => SourcingMappingStatus::Removed->value,
                'removed_by' => $actor->id,
                'removed_at' => $now,
                'removal_reason' => 'Product removed from the group: '.$reason,
            ]);

            $membership->forceFill([
                'status' => SourcingMappingStatus::Removed,
                'removed_by' => $actor->id,
                'removed_at' => $now,
                'removal_reason' => $reason,
            ])->save();

            $this->record('sourcing_group.product_removed', $group, $actor, before: [
                'product' => $product->public_id,
            ], reason: $reason);
        });
    }

    /**
     * Declare that a member product's variation fulfils a canonical variation.
     *
     * A product with variations must be mapped variation by variation -- never
     * at product level -- and a variation can hold one active mapping only.
     * Both sides are checked against the product they claim to belong to, so a
     * mapping can never point at a variation of some other product.
     */
    public function mapVariant(
        User $actor,
        ProductSourcingGroup $group,
        Product $product,
        ?ProductVariant $variant,
        ?ProductVariant $canonicalVariant,
        string $reason,
    ): ProductSourcingVariantMapping {
        $this->requireReason($reason);

        return $this->database->transaction(function () use ($actor, $group, $product, $variant, $canonicalVariant, $reason) {
            /** @var ProductSourcingGroup $locked */
            $locked = ProductSourcingGroup::query()->lockForUpdate()->findOrFail($group->id);

            if (! $locked->is_active) {
                throw SourcingGroupRefused::inactiveGroup();
            }

            if (! $locked->products()->active()->where('product_id', $product->id)->exists()) {
                throw SourcingGroupRefused::notAMember();
            }

            $canonicalProduct = $locked->products()->active()->where('is_canonical', true)->with('product')->firstOrFail()->product;

            if ($variant !== null && $variant->product_id !== $product->id) {
                throw SourcingGroupRefused::variantNotOfProduct();
            }

            if ($canonicalVariant !== null && $canonicalVariant->product_id !== $canonicalProduct->id) {
                throw SourcingGroupRefused::variantNotOfProduct();
            }

            if ($variant === null && $product->variants()->exists()) {
                throw SourcingGroupRefused::variantRequired($product->name);
            }

            if ($canonicalVariant === null && $canonicalProduct->variants()->exists()) {
                throw SourcingGroupRefused::variantRequired($canonicalProduct->name);
            }

            $taken = ProductSourcingVariantMapping::query()
                ->active()
                ->where('product_id', $product->id)
                ->where('product_variant_id', $variant?->id)
                ->exists();

            if ($taken) {
                throw SourcingGroupRefused::alreadyMapped();
            }

            $mapping = $locked->variantMappings()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'canonical_product_variant_id' => $canonicalVariant?->id,
                'status' => SourcingMappingStatus::Active,
                'added_by' => $actor->id,
                'added_reason' => $reason,
            ]);

            $this->record('sourcing_group.variant_mapped', $locked, $actor, after: [
                'product' => $product->public_id,
                'variant' => $variant?->public_id,
                'canonical_variant' => $canonicalVariant?->public_id,
            ], reason: $reason);

            return $mapping;
        });
    }

    public function unmapVariant(User $actor, ProductSourcingVariantMapping $mapping, string $reason): void
    {
        $this->requireReason($reason);

        $this->database->transaction(function () use ($actor, $mapping, $reason) {
            /** @var ProductSourcingVariantMapping $locked */
            $locked = ProductSourcingVariantMapping::query()->lockForUpdate()->findOrFail($mapping->id);

            if ($locked->status !== SourcingMappingStatus::Active) {
                return;
            }

            if ($this->hasOpenAllocationOnProduct($locked->product, $locked->product_variant_id, $locked->sourcing_group_id)) {
                throw SourcingGroupRefused::inUseByOpenAllocation();
            }

            $locked->forceFill([
                'status' => SourcingMappingStatus::Removed,
                'removed_by' => $actor->id,
                'removed_at' => now(),
                'removal_reason' => $reason,
            ])->save();

            $this->record('sourcing_group.variant_unmapped', $locked->group, $actor, before: [
                'product' => $locked->product->public_id,
                'variant' => $locked->variant?->public_id,
                'canonical_variant' => $locked->canonicalVariant?->public_id,
            ], reason: $reason);
        });
    }

    /**
     * Whether an active allocation fulfils an order line of this group from
     * the given product's offer or stock -- the only case that relies on the
     * membership or mapping. Judged from the group frozen on the order line,
     * so a same-product allocation (which never needed one) is not counted.
     */
    protected function hasOpenAllocationOnProduct(Product $product, ?int $variantId = null, ?int $groupId = null): bool
    {
        $offerIds = SupplierOffer::query()
            ->where('product_id', $product->id)
            ->when($variantId !== null, fn ($query) => $query->where('product_variant_id', $variantId))
            ->pluck('id');

        $stockItemIds = StockItem::query()
            ->where('product_id', $product->id)
            ->when($variantId !== null, fn ($query) => $query->where('product_variant_id', $variantId))
            ->pluck('id');

        return OrderItemAllocation::query()
            ->where('status', AllocationStatus::Active)
            ->where(fn ($query) => $query
                ->whereIn('supplier_offer_id', $offerIds)
                ->orWhereIn('linked_stock_item_id', $stockItemIds))
            ->whereHas('orderItem', fn ($query) => $query
                ->where('product_id', '!=', $product->id)
                ->when($groupId !== null, fn ($inner) => $inner->where('sourcing_group_id', $groupId)))
            ->exists();
    }

    protected function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw SourcingGroupRefused::reasonRequired();
        }
    }

    /**
     * @param  array<array-key, mixed>|null  $before
     * @param  array<array-key, mixed>|null  $after
     */
    protected function record(
        string $action,
        ProductSourcingGroup $group,
        User $actor,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: ProductSourcingGroup::class,
            auditableId: $group->id,
            before: $before,
            after: $after,
            reason: $reason,
            module: PermissionModule::SourcingGroup->value,
        ));
    }
}
