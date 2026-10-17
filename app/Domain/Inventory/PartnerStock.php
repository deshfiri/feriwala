<?php

namespace App\Domain\Inventory;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\PartnerStockItem;
use App\Domain\Inventory\Models\PartnerStockMovement;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The stock a Partner owns because they bought it (D34).
 *
 * The same discipline as the central {@see StockLedger}: every change is a
 * movement between buckets (or into or out of the Partner's stock altogether),
 * written under the item's row lock in the same transaction as the figures, with
 * each bucket's figure before and after. A source bucket is checked **inside**
 * the lock, so two orders arriving together cannot both read "1 available"; the
 * CHECK constraint underneath refuses a negative figure if anything gets past.
 *
 * Idempotent when given a key: a retried command returns the movement it already
 * wrote, and two identical commands racing are settled by the unique index.
 *
 * One row per Partner, Product and **exact Variation**: another Partner's units,
 * another Variation's units and central stock are never added in.
 */
class PartnerStock
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * The Partner's stock row for one unit, created empty when asked to.
     */
    public function itemFor(BusinessAccount $account, int $productId, ?int $variantId, bool $create = false): ?PartnerStockItem
    {
        $find = fn (): ?PartnerStockItem => PartnerStockItem::query()
            ->where('business_account_id', $account->id)
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->first();

        $item = $find();

        if ($item !== null || ! $create) {
            return $item;
        }

        try {
            return PartnerStockItem::create([
                'business_account_id' => $account->id,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
            ])->refresh();
        } catch (UniqueConstraintViolationException) {
            // Two first purchases of the same unit raced; the index kept one row.
            return $find();
        }
    }

    /**
     * Give the Partner `$quantity` units they have bought.
     */
    public function receive(BusinessAccount $account, int $productId, ?int $variantId, int $quantity, MovementContext $context = new MovementContext): PartnerStockMovement
    {
        $item = $this->itemFor($account, $productId, $variantId, create: true);

        assert($item instanceof PartnerStockItem);

        return $this->move($item, null, StockBucket::Available, $quantity, PartnerStockMovementType::PurchaseReceived, $context);
    }

    /**
     * Take back the units a purchase gave, because it stopped counting.
     *
     * Only what is still available can leave: units already reserved for an
     * order, sold, returned or damaged are accounted for elsewhere and stay as
     * they are. Returns how many left.
     */
    public function reverse(BusinessAccount $account, int $productId, ?int $variantId, int $quantity, MovementContext $context = new MovementContext): int
    {
        $item = $this->itemFor($account, $productId, $variantId);

        if ($item === null) {
            return 0;
        }

        return $this->database->transaction(function () use ($item, $quantity, $context) {
            /** @var PartnerStockItem $locked */
            $locked = PartnerStockItem::query()->lockForUpdate()->findOrFail($item->id);
            $leaving = min($quantity, $locked->available);

            if ($leaving > 0) {
                $this->move($locked, StockBucket::Available, null, $leaving, PartnerStockMovementType::PurchaseReversed, $context);
            }

            return $leaving;
        });
    }

    /**
     * Move `$quantity` units out of `$from` and into `$to`.
     *
     * @throws InventoryRefused when `$from` holds fewer than `$quantity`
     * @throws InvalidArgumentException when the movement moves nothing
     */
    public function move(
        PartnerStockItem $item,
        ?StockBucket $from,
        ?StockBucket $to,
        int $quantity,
        PartnerStockMovementType $type,
        MovementContext $context = new MovementContext,
    ): PartnerStockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A stock movement moves at least one unit.');
        }

        if ($from === null && $to === null) {
            throw new InvalidArgumentException('A stock movement takes from a bucket, puts into one, or both.');
        }

        if ($from !== null && $from === $to) {
            throw new InvalidArgumentException('A stock movement cannot move units into the bucket they are already in.');
        }

        if ($existing = $this->findByKey($context->idempotencyKey)) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use ($item, $from, $to, $quantity, $type, $context) {
                /** @var PartnerStockItem $locked */
                $locked = PartnerStockItem::query()->lockForUpdate()->findOrFail($item->id);

                $before = $locked->buckets();

                if ($from !== null && $before[$from->value] < $quantity) {
                    throw InventoryRefused::insufficient($from, $before[$from->value], $quantity);
                }

                $changes = [];

                if ($from !== null) {
                    $changes[$from->value] = $before[$from->value] - $quantity;
                }

                if ($to !== null) {
                    $changes[$to->value] = $before[$to->value] + $quantity;
                }

                $locked->forceFill($changes)->save();

                $movement = PartnerStockMovement::create([
                    'partner_stock_item_id' => $locked->id,
                    'business_account_id' => $locked->business_account_id,
                    'product_id' => $locked->product_id,
                    'product_variant_id' => $locked->product_variant_id,
                    'type' => $type,
                    'from_bucket' => $from,
                    'to_bucket' => $to,
                    'quantity' => $quantity,
                    'before' => $before,
                    'after' => $locked->buckets(),
                    'reason' => $context->reason,
                    'actor_id' => $context->actorId,
                    'source_type' => $context->sourceType,
                    'source_id' => $context->sourceId,
                    'idempotency_key' => $context->idempotencyKey,
                    'occurred_at' => now(),
                ]);

                $item->setRawAttributes($locked->getAttributes(), sync: true);

                return $movement;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findByKey($context->idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    /**
     * What each unit has available for sale, keyed `productId:variantId` (the
     * variant part empty for a Product with no variations).
     *
     * A unit the Partner never bought is simply absent: zero, not unknown.
     *
     * @param  array<int, array{product_id: int, variant_id: int|null}>  $units
     * @return array<string, int>
     */
    public function availableFor(BusinessAccount $account, array $units): array
    {
        if ($units === []) {
            return [];
        }

        return DB::table('partner_stock_items')
            ->where('business_account_id', $account->id)
            ->whereIn('product_id', array_values(array_unique(array_column($units, 'product_id'))))
            ->get(['product_id', 'product_variant_id', 'available'])
            ->mapWithKeys(fn (object $row) => [$row->product_id.':'.($row->product_variant_id ?? '') => (int) $row->available])
            ->all();
    }

    protected function findByKey(?string $key): ?PartnerStockMovement
    {
        return $key === null
            ? null
            : PartnerStockMovement::query()->where('idempotency_key', $key)->first();
    }
}
