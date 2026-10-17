<?php

namespace App\Domain\Order\Exceptions;

use RuntimeException;

/**
 * A Store Order move the workflow does not allow, refused before anything is written.
 */
class StoreOrderRefused extends RuntimeException
{
    public static function notStoreOrder(): self
    {
        return new self(__('store_orders.refused.not_store_order'));
    }

    public static function wrongStep(string $action, string $current): self
    {
        return new self(__('store_orders.refused.wrong_step', ['action' => $action, 'current' => $current]));
    }

    public static function reasonRequired(): self
    {
        return new self(__('store_orders.refused.reason_required'));
    }

    public static function cannotCancelNow(): self
    {
        return new self(__('store_orders.refused.cannot_cancel_now'));
    }

    public static function notYetDispatched(): self
    {
        return new self(__('store_orders.refused.not_yet_dispatched'));
    }

    public static function stockNotHeld(string $sku): self
    {
        return new self(__('store_orders.refused.stock_not_held', ['sku' => $sku]));
    }
}
