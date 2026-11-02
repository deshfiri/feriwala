<?php

namespace App\Console\Commands;

use App\Services\ApiCache;
use App\Services\Basket;
use App\Services\Checkouts;
use App\Services\Webhooks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('storefront:prune')]
#[Description('Remove expired sessions and cached reads, abandoned baskets and unplaced checkouts, and old webhook delivery ids')]
class PruneStorefront extends Command
{
    public function handle(ApiCache $cache): int
    {
        $removed = [
            'sessions' => DB::table('sessions')->where('last_activity', '<', time() - (int) config('session.lifetime') * 60)->delete(),
            'cache' => $cache->prune(),
            'carts' => Basket::prune(30),
            'checkouts' => Checkouts::prune(14),
            'webhook deliveries' => Webhooks::prune(14),
        ];

        foreach ($removed as $what => $count) {
            $this->line(str_pad($what, 20).$count);
        }

        return self::SUCCESS;
    }
}
