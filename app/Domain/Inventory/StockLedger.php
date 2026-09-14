<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Actions\RespondToStockChange;
use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * The only thing that changes a stock figure (§19, §36.1).
 *
 * Every bucket change goes through here, and every one writes its movement —
 * with every bucket's figure before and after — in the **same transaction** as
 * the figures it explains. Nothing else writes a bucket column: a count that
 * changed without a movement is a count nobody can account for.
 *
 * One operation, because every stock change is the same shape: some units leave
 * one bucket and arrive in another. A missing `from` is stock arriving at central
 * stock; a missing `to` is stock leaving it.
 *
 * Every write runs under `lockForUpdate` inside a transaction, and the source
 * bucket is checked **inside** the lock. Two reservations arriving together must
 * not both read the same "1 available" and both succeed; the row lock is what
 * stops them, and the CHECK constraint underneath refuses a negative figure if
 * anything ever gets past it (§19: overselling prevention).
 *
 * Idempotent when given a key: a retried command returns the movement it already
 * wrote, and two identical commands racing are settled by the unique index.
 */
class StockLedger
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * Move `$quantity` units out of `$from` and into `$to`.
     *
     * @throws InventoryRefused when `$from` holds fewer than `$quantity`
     * @throws InvalidArgumentException when the movement moves nothing
     */
    public function move(
        StockItem $item,
        ?StockBucket $from,
        ?StockBucket $to,
        int $quantity,
        StockMovementType $type,
        MovementContext $context = new MovementContext,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A stock movement moves at least one unit.');
        }

        if ($from === null && $to === null) {
            throw new InvalidArgumentException('A stock movement takes from a bucket, puts into one, or both.');
        }

        if ($from !== null && $from === $to) {
            throw new InvalidArgumentException('A stock movement cannot move units into the bucket they are already in.');
        }

        // Fast path, and the reason a retry costs nothing.
        if ($existing = $this->findByKey($context->idempotencyKey)) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use ($item, $from, $to, $quantity, $type, $context) {
                /** @var StockItem $locked */
                $locked = StockItem::query()->lockForUpdate()->findOrFail($item->id);

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

                $movement = StockMovement::create([
                    'stock_item_id' => $locked->id,
                    'warehouse_id' => $locked->warehouse_id,
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

                // What is available changed: once it has committed, see whether
                // the product should come off sale or go back on (P3-28).
                if ($from === StockBucket::Available || $to === StockBucket::Available) {
                    $productId = $locked->product_id;

                    $this->database->connection()->afterCommit(
                        fn () => app(RespondToStockChange::class)->handle($productId),
                    );
                }

                return $movement;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Two identical commands raced and the index settled it. The loser's
            // transaction rolled back whole; the winner's movement answers both.
            $existing = $this->findByKey($context->idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    protected function findByKey(?string $key): ?StockMovement
    {
        return $key === null
            ? null
            : StockMovement::query()->where('idempotency_key', $key)->first();
    }
}
