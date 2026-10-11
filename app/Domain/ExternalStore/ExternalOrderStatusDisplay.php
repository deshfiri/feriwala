<?php

namespace App\Domain\ExternalStore;

/**
 * How a status reported by an external store reads in Feriwala (D29).
 *
 * The store's word, translated where Feriwala knows it and shown as the store
 * wrote it where not. It is display only: nothing here maps a store's status
 * onto Feriwala's own order statuses, which these orders never enter.
 */
final class ExternalOrderStatusDisplay
{
    /** @var array<string, string> */
    private const TONES = [
        'pending' => 'info',
        'confirmed' => 'info',
        'processing' => 'info',
        'packed' => 'info',
        'shipped' => 'info',
        'out_for_delivery' => 'info',
        'delivered' => 'success',
        'completed' => 'success',
        'paid' => 'success',
        'unpaid' => 'warning',
        'partially_paid' => 'warning',
        'cancelled' => 'neutral',
        'returned' => 'warning',
        'refunded' => 'warning',
        'failed' => 'danger',
    ];

    public static function label(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $key = 'stores.order_statuses.'.$status;
        $line = __($key);

        return $line === $key ? ucfirst(str_replace(['_', '-'], ' ', $status)) : $line;
    }

    public static function tone(?string $status): string
    {
        return self::TONES[$status ?? ''] ?? 'neutral';
    }
}
