<?php

namespace App\Domain\Supplier;

use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\StockLedger;
use App\Domain\Supplier\Models\SupplierOfferStock;
use App\Domain\Supplier\Models\SupplierStockMovement;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * The only thing that moves a Supplier stock bucket between buckets (D25,
 * P13-28) — the Supplier-side twin of {@see StockLedger}.
 *
 * Same contract, deliberately: units leave one bucket and arrive in another, the
 * source bucket is checked **inside** the row lock, and the movement — with every
 * bucket's figure before and after — is written in the same transaction as the
 * figures it explains. Two reservations racing for the last unit both read the
 * stock row's lock, so the second sees zero and is refused; the CHECK
 * constraints underneath refuse a negative bucket if anything ever gets past.
 *
 * Idempotent when given a key: a retried command returns the movement it already
 * wrote, and two identical commands racing are settled by the unique index. It
 * never touches `stock_items` — central and Supplier stock stay separate sources.
 *
 * Direct availability changes a Supplier submits and staff approve
 * (`DecideSupplierStockUpdate`, `AdjustSupplierOfferStock`) are a different
 * operation — a figure set, not units moved — and keep their own movements.
 */
class SupplierStockLedger
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * Move `$quantity` units out of `$from` and into `$to`.
     *
     * @param  string  $source  one of the movement sources the table allows
     *
     * @throws InventoryRefused when `$from` holds fewer than `$quantity`
     * @throws InvalidArgumentException when the movement moves nothing
     */
    public function move(
        SupplierOfferStock $stock,
        StockBucket $from,
        StockBucket $to,
        int $quantity,
        string $source,
        ?int $reservationId = null,
        ?string $idempotencyKey = null,
        ?string $reason = null,
    ): SupplierStockMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A stock movement moves at least one unit.');
        }

        if ($from === $to) {
            throw new InvalidArgumentException('A stock movement cannot move units into the bucket they are already in.');
        }

        if ($existing = $this->findByKey($idempotencyKey)) {
            return $existing;
        }

        try {
            return $this->database->transaction(function () use ($stock, $from, $to, $quantity, $source, $reservationId, $idempotencyKey, $reason) {
                /** @var SupplierOfferStock $locked */
                $locked = SupplierOfferStock::query()->lockForUpdate()->findOrFail($stock->id);

                $before = $locked->buckets();

                if ($before[$from->value] < $quantity) {
                    throw InventoryRefused::insufficient($from, $before[$from->value], $quantity);
                }

                $fromColumn = SupplierOfferStock::column($from);
                $toColumn = SupplierOfferStock::column($to);

                $locked->forceFill([
                    $fromColumn => $locked->{$fromColumn} - $quantity,
                    $toColumn => $locked->{$toColumn} + $quantity,
                ])->save();

                $after = $locked->buckets();

                $movement = SupplierStockMovement::create([
                    'supplier_offer_id' => $locked->supplier_offer_id,
                    'quantity_before' => $before[StockBucket::Available->value],
                    'quantity_after' => $after[StockBucket::Available->value],
                    'moved_quantity' => $quantity,
                    'from_bucket' => $from->value,
                    'to_bucket' => $to->value,
                    'buckets_before' => $before,
                    'buckets_after' => $after,
                    'source' => $source,
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'reason' => $reason,
                    'stock_reservation_id' => $reservationId,
                    'idempotency_key' => $idempotencyKey,
                    'created_at' => now(),
                ]);

                $stock->setRawAttributes($locked->getAttributes(), sync: true);

                return $movement;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findByKey($idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    protected function findByKey(?string $key): ?SupplierStockMovement
    {
        return $key === null
            ? null
            : SupplierStockMovement::query()->where('idempotency_key', $key)->first();
    }
}
