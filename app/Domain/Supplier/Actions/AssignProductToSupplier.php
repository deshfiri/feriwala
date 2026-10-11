<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Sourcing\Policies\ProductSupplierPolicy;
use App\Domain\Supplier\Enums\OfferOrigin;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierOfferStock;
use App\Domain\Supplier\Models\SupplierStockMovement;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Staff assigning an existing Product (or one variation) to an approved
 * Supplier — the second way a Supplier reaches a Product, beside an approved
 * listing. It produces the same {@see SupplierOffer} a listing approval does,
 * marked `admin_assignment`.
 *
 * The **Supplier Base Price** is the offer's `supplier_rate`: what is payable
 * to that Supplier for one unit. It is required and positive. The platform's
 * own pricing is never edited here — the offer's `platform_rate` is seeded
 * from the Product's (or variation's) wholesale price, read not changed, and
 * the assignment is refused when that would sit below the base price, the
 * same rule every offer lives under.
 *
 * One live offer per Supplier and Product/variation: a second is refused, and
 * so is one that would duplicate a suspended offer — that one is reactivated,
 * not copied.
 */
class AssignProductToSupplier
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array{supply_mode?: SupplyMode|null, quantity?: int|null, fulfilment_capacity?: int|null, lead_time_days?: int|null, reason?: string|null, wholesale_enabled?: bool, dropshipping_enabled?: bool}  $terms
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function handle(User $actor, Product $product, ?ProductVariant $variant, Supplier $supplier, Money $baseRate, array $terms = []): SupplierOffer
    {
        if (! ProductSupplierPolicy::canAssign($actor)) {
            throw new AuthorizationException('You may not assign Products to Suppliers.');
        }

        if (! $baseRate->isPositive()) {
            throw new InvalidArgumentException('The Supplier Base Price must be greater than zero.');
        }

        if (! $supplier->isOperational()) {
            throw new InvalidArgumentException('Only an approved Supplier can be assigned a Product.');
        }

        if ($variant !== null && $variant->product_id !== $product->id) {
            throw new InvalidArgumentException('That variation does not belong to this Product.');
        }

        if ($variant === null && $product->variants()->where('is_active', true)->exists()) {
            throw new InvalidArgumentException('Choose the variation this Supplier has.');
        }

        $mode = $terms['supply_mode'] ?? SupplyMode::ReadyStock;
        $quantity = $terms['quantity'] ?? null;

        if ($quantity !== null && $quantity < 0) {
            throw new InvalidArgumentException('Available quantity cannot be negative.');
        }

        $platformRate = $variant?->effectiveWholesalePrice() ?? $product->wholesale_price;

        if ($platformRate->currency !== $baseRate->currency) {
            throw new InvalidArgumentException('The Supplier Base Price must use the Product\'s currency.');
        }

        if ($platformRate->lessThan($baseRate)) {
            throw new InvalidArgumentException('The Supplier Base Price cannot be higher than the Product\'s wholesale price.');
        }

        $reason = $this->clean($terms['reason'] ?? null) ?? 'Assigned to the Supplier by staff.';

        return $this->database->transaction(function () use ($actor, $product, $variant, $supplier, $baseRate, $platformRate, $mode, $quantity, $terms, $reason) {
            // Everyone assigning on this Product queues here, so the duplicate
            // check below cannot be passed twice at once.
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $existing = SupplierOffer::query()
                ->where('supplier_id', $supplier->id)
                ->where('product_id', $product->id)
                ->where('product_variant_id', $variant?->id)
                ->get();

            if ($existing->contains(fn (SupplierOffer $offer) => $offer->status === OfferStatus::Active)) {
                throw new InvalidArgumentException("{$supplier->business_name} is already assigned to this Product.");
            }

            if ($existing->isNotEmpty()) {
                throw new InvalidArgumentException("{$supplier->business_name} already has a suspended offer on this Product. Reactivate it instead of assigning again.");
            }

            $offer = SupplierOffer::create([
                'supplier_id' => $supplier->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'status' => OfferStatus::Active,
                'origin' => OfferOrigin::AdminAssignment,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'wholesale_enabled' => (bool) ($terms['wholesale_enabled'] ?? false),
                'dropshipping_enabled' => (bool) ($terms['dropshipping_enabled'] ?? false),
                'supplier_rate' => $baseRate,
                'platform_rate' => $platformRate,
                'currency_code' => $baseRate->currency->value,
                'supply_mode' => $mode,
                'lead_time_days' => $terms['lead_time_days'] ?? null,
                'fulfilment_capacity' => $terms['fulfilment_capacity'] ?? null,
                'activated_by' => $actor->id,
                'activated_at' => now(),
            ]);

            $offer->priceHistory()->create([
                'supplier_rate' => $baseRate,
                'platform_rate' => $platformRate,
                'currency_code' => $baseRate->currency->value,
                'effective_from' => now(),
                'changed_by' => $actor->id,
                'reason' => 'Initial Supplier Base Price set at assignment. '.$reason,
                'created_at' => now(),
            ]);

            if ($mode === SupplyMode::ReadyStock && $quantity !== null) {
                $this->openStock($offer, $quantity, $actor);
            }

            $this->audit->handle(new AuditEntry(
                action: 'supplier_offer.assigned',
                actorId: $actor->id,
                auditableType: SupplierOffer::class,
                auditableId: $offer->id,
                after: [
                    'origin' => OfferOrigin::AdminAssignment->value,
                    'supplier' => $supplier->public_id,
                    'product' => $product->public_id,
                    'variant' => $variant?->public_id,
                    'supplier_base_price' => $baseRate->toDecimal(),
                    'supply_mode' => $mode->value,
                ],
                reason: $reason,
                module: PermissionModule::SupplierPricing->value,
            ));

            return $offer;
        });
    }

    protected function openStock(SupplierOffer $offer, int $quantity, User $actor): void
    {
        /** @var SupplierOfferStock $stock */
        $stock = $offer->stock()->create(['quantity' => $quantity]);

        $buckets = $stock->buckets();

        SupplierStockMovement::create([
            'supplier_offer_id' => $offer->id,
            'quantity_before' => 0,
            'quantity_after' => $quantity,
            'moved_quantity' => $quantity > 0 ? $quantity : null,
            'to_bucket' => $quantity > 0 ? StockBucket::Available->value : null,
            'buckets_before' => array_fill_keys(array_keys($buckets), 0),
            'buckets_after' => $buckets,
            'source' => 'initial',
            'actor_type' => 'staff',
            'actor_id' => $actor->id,
            'reason' => "Opening availability of {$quantity} set when the Product was assigned.",
            'idempotency_key' => 'supplier-offer-opening:'.$offer->public_id,
            'created_at' => now(),
        ]);
    }

    protected function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
