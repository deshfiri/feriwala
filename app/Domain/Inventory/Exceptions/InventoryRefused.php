<?php

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\Enums\StockBucket;
use RuntimeException;

/**
 * An inventory change the rules do not allow, refused before anything is written.
 *
 * A refusal is an answer to what was asked — "that warehouse is switched off",
 * "there are not that many available" — so each carries the message a person is
 * shown, in their language.
 */
class InventoryRefused extends RuntimeException
{
    public static function warehouseInactive(): self
    {
        return new self(__('inventory.refused.warehouse_inactive'));
    }

    public static function defaultMustStayActive(): self
    {
        return new self(__('inventory.refused.default_must_stay_active'));
    }

    public static function defaultMustBeActive(): self
    {
        return new self(__('inventory.refused.default_must_be_active'));
    }

    public static function chooseVariation(): self
    {
        return new self(__('inventory.refused.choose_variation'));
    }

    public static function variationNotOfProduct(): self
    {
        return new self(__('inventory.refused.variation_not_of_product'));
    }

    public static function alreadyTracked(): self
    {
        return new self(__('inventory.refused.already_tracked'));
    }

    /**
     * A bucket holds fewer units than were asked of it (§19: overselling
     * prevention). Read under the row lock, so the figure is the true one.
     */
    public static function insufficient(StockBucket $bucket, int $held, int $requested): self
    {
        return new self(__('inventory.refused.insufficient', [
            'bucket' => __('inventory.buckets.'.$bucket->value),
            'held' => $held,
            'requested' => $requested,
        ]));
    }
}
