<?php

namespace App\Domain\Inventory\Actions;

use Throwable;

/**
 * What follows a change to a product's available stock (§19).
 *
 * Called once the stock change has committed, never inside it: the change to the
 * figures is the fact, and what follows from it must not be able to undo it. A
 * failure here is reported and the stock change stands; the next change to the
 * same product tries again.
 */
class RespondToStockChange
{
    public function __construct(
        protected SyncProductStockStatus $status,
    ) {}

    public function handle(int $productId): void
    {
        try {
            $this->status->handle($productId);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
