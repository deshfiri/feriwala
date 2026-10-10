<?php

namespace App\Domain\Sourcing\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Enums\ProductLinkStatus;
use App\Domain\Sourcing\Enums\SourcingMappingStatus;
use App\Domain\Sourcing\Exceptions\ProductLinkRefused;
use App\Domain\Sourcing\Models\ProductLink;
use App\Domain\Sourcing\Models\ProductLinkVariantMapping;
use App\Domain\Sourcing\Policies\ProductLinkPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * Every change to a Same Product link goes through here, so the rules and the
 * audit trail live in one place.
 *
 * A link is confirmed by a person, always. Nothing is deleted: an unlink or an
 * unmap records who, when and why, and the row stays as history. Changing a link
 * never touches an allocation that already exists — an allocation snapshots the
 * source it was made from, so what was true when it was made stays true.
 */
class ManageProductLinks
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * Confirm that two Product records are the same physical Product.
     *
     * Either argument order gives the same row: the pair is stored once, lower
     * Product id first.
     *
     * @throws AuthorizationException
     * @throws ProductLinkRefused
     */
    public function link(User $actor, Product $one, Product $other, ?string $reason = null): ProductLink
    {
        ProductLinkPolicy::authorize(ProductLinkPolicy::canLink($actor), 'You may not link Products.');

        if ($one->is($other)) {
            throw ProductLinkRefused::selfLink();
        }

        [$first, $second] = $one->id < $other->id ? [$one, $other] : [$other, $one];

        return $this->database->transaction(function () use ($actor, $first, $second, $reason) {
            // Locked in id order, so two staff linking the same pair from
            // opposite ends queue rather than deadlock.
            Product::query()->whereIn('id', [$first->id, $second->id])->orderBy('id')->lockForUpdate()->get();

            $exists = ProductLink::query()->active()
                ->where('product_a_id', $first->id)
                ->where('product_b_id', $second->id)
                ->exists();

            if ($exists) {
                throw ProductLinkRefused::alreadyLinked($first->name, $second->name);
            }

            $link = ProductLink::create([
                'product_a_id' => $first->id,
                'product_b_id' => $second->id,
                'status' => ProductLinkStatus::Active,
                'linked_by' => $actor->id,
                'linked_at' => now(),
                'link_reason' => $this->clean($reason),
            ]);

            $this->record('product_link.linked', $link, $actor, after: [
                'product_a' => $first->public_id,
                'product_b' => $second->public_id,
            ], reason: $reason);

            return $link;
        });
    }

    /**
     * Remove one direct connection, and with it every variation match on it.
     *
     * Only that edge goes: Products still connected through some other path
     * stay connected, and Products reachable only through it stop being.
     *
     * @throws AuthorizationException
     * @throws ProductLinkRefused
     */
    public function unlink(User $actor, ProductLink $link, ?string $reason = null): void
    {
        ProductLinkPolicy::authorize(ProductLinkPolicy::canUnlink($actor), 'You may not unlink Products.');

        $this->database->transaction(function () use ($actor, $link, $reason) {
            /** @var ProductLink $locked */
            $locked = ProductLink::query()->lockForUpdate()->findOrFail($link->id);

            if ($locked->status !== ProductLinkStatus::Active) {
                throw ProductLinkRefused::notLinked();
            }

            $now = now();

            $locked->variantMappings()->active()->update([
                'status' => SourcingMappingStatus::Removed->value,
                'removed_by' => $actor->id,
                'removed_at' => $now,
                'removal_reason' => 'The Products were unlinked.',
            ]);

            $locked->forceFill([
                'status' => ProductLinkStatus::Unlinked,
                'unlinked_by' => $actor->id,
                'unlinked_at' => $now,
                'unlink_reason' => $this->clean($reason),
            ])->save();

            $this->record('product_link.unlinked', $locked, $actor, before: [
                'product_a' => $locked->productA->public_id,
                'product_b' => $locked->productB->public_id,
            ], reason: $reason);
        });
    }

    /**
     * Say a variation of one linked Product is the same as a variation of the
     * other.
     *
     * Sides follow the link: `$variantA` belongs to its lower-id Product. A
     * Product with variations must be matched variation by variation; a null
     * side is only for a Product that has none.
     *
     * @throws AuthorizationException
     * @throws ProductLinkRefused
     */
    public function mapVariants(User $actor, ProductLink $link, ?ProductVariant $variantA, ?ProductVariant $variantB, ?string $reason = null): ProductLinkVariantMapping
    {
        ProductLinkPolicy::authorize(ProductLinkPolicy::canUnlink($actor), 'You may not match variations.');

        return $this->database->transaction(function () use ($actor, $link, $variantA, $variantB, $reason) {
            /** @var ProductLink $locked */
            $locked = ProductLink::query()->with(['productA', 'productB'])->lockForUpdate()->findOrFail($link->id);

            if ($locked->status !== ProductLinkStatus::Active) {
                throw ProductLinkRefused::notLinked();
            }

            $this->assertSide($locked->productA, $variantA);
            $this->assertSide($locked->productB, $variantB);

            $exists = $locked->variantMappings()->active()
                ->where('variant_a_id', $variantA?->id)
                ->where('variant_b_id', $variantB?->id)
                ->exists();

            if ($exists) {
                throw ProductLinkRefused::alreadyMapped();
            }

            $mapping = $locked->variantMappings()->create([
                'variant_a_id' => $variantA?->id,
                'variant_b_id' => $variantB?->id,
                'status' => SourcingMappingStatus::Active,
                'mapped_by' => $actor->id,
                'mapped_at' => now(),
                'map_reason' => $this->clean($reason),
            ]);

            $this->record('product_link.variants_matched', $locked, $actor, after: [
                'variant_a' => $variantA?->public_id,
                'variant_b' => $variantB?->public_id,
            ], reason: $reason);

            return $mapping;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ProductLinkRefused
     */
    public function unmapVariants(User $actor, ProductLinkVariantMapping $mapping, ?string $reason = null): void
    {
        ProductLinkPolicy::authorize(ProductLinkPolicy::canUnlink($actor), 'You may not match variations.');

        $this->database->transaction(function () use ($actor, $mapping, $reason) {
            /** @var ProductLinkVariantMapping $locked */
            $locked = ProductLinkVariantMapping::query()->lockForUpdate()->findOrFail($mapping->id);

            if ($locked->status !== SourcingMappingStatus::Active) {
                throw ProductLinkRefused::notMapped();
            }

            $locked->forceFill([
                'status' => SourcingMappingStatus::Removed,
                'removed_by' => $actor->id,
                'removed_at' => now(),
                'removal_reason' => $this->clean($reason),
            ])->save();

            $this->record('product_link.variants_unmatched', $locked->link, $actor, before: [
                'variant_a' => $locked->variantA?->public_id,
                'variant_b' => $locked->variantB?->public_id,
            ], reason: $reason);
        });
    }

    /**
     * @throws ProductLinkRefused
     */
    protected function assertSide(Product $product, ?ProductVariant $variant): void
    {
        if ($variant !== null && $variant->product_id !== $product->id) {
            throw ProductLinkRefused::variantNotOfProduct();
        }

        $hasVariants = $product->variants()->exists();

        if ($variant === null && $hasVariants) {
            throw ProductLinkRefused::variantRequired($product->name);
        }

        if ($variant !== null && ! $hasVariants) {
            throw ProductLinkRefused::variantsNotAllowed($product->name);
        }
    }

    protected function clean(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        return $reason === '' ? null : $reason;
    }

    /**
     * @param  array<array-key, mixed>|null  $before
     * @param  array<array-key, mixed>|null  $after
     */
    protected function record(
        string $action,
        ProductLink $link,
        User $actor,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): void {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: ProductLink::class,
            auditableId: $link->id,
            before: $before,
            after: $after,
            reason: $this->clean($reason),
            module: PermissionModule::ProductLink->value,
        ));
    }
}
