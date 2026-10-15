#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Housekeeping: expired sessions and cache, abandoned baskets and unplaced
 * checkouts, old webhook delivery ids. Run it from cron, hourly is plenty:
 *
 *     0 * * * * php /path/to/storefront/bin/prune.php
 */

require dirname(__DIR__).'/src/bootstrap.php';

use Storefront\Domain\Cart;
use Storefront\Domain\Checkout;
use Storefront\Domain\Webhooks;
use Storefront\Http\Session;
use Storefront\Support\ApiCache;
use Storefront\Support\Db;
use Storefront\Support\Throttle;

$removed = [
    'sessions' => Db::exec('DELETE FROM sessions WHERE last_activity < ?', [time() - Session::lifetime()]),
    'cache' => ApiCache::prune(),
    'carts' => Cart::prune(30),
    'checkouts' => Checkout::prune(14),
    'webhook deliveries' => Webhooks::prune(14),
    'throttle' => Throttle::prune(),
];

foreach ($removed as $what => $count) {
    echo str_pad($what, 20).$count."\n";
}
