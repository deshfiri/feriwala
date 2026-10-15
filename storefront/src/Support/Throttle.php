<?php

declare(strict_types=1);

namespace Storefront\Support;

/**
 * A fixed-window counter in MySQL, for the few endpoints that must not be
 * hammered: looking an order up by reference and mobile number, mainly.
 */
final class Throttle
{
    /**
     * Count a hit; true when it is still within the limit.
     */
    public static function hit(string $bucket, int $limit, int $windowSeconds): bool
    {
        $window = intdiv(time(), $windowSeconds) * $windowSeconds;

        Db::exec(
            'INSERT INTO throttle (bucket, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            [substr($bucket, 0, 190), $window],
        );

        return (int) Db::value('SELECT hits FROM throttle WHERE bucket = ? AND window_start = ?', [substr($bucket, 0, 190), $window]) <= $limit;
    }

    public static function prune(int $olderThanSeconds = 86400): int
    {
        return Db::exec('DELETE FROM throttle WHERE window_start < ?', [time() - $olderThanSeconds]);
    }
}
