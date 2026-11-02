<?php

use Illuminate\Support\Facades\Schedule;

// Housekeeping: abandoned baskets and unplaced checkouts, old webhook delivery
// ids, expired cached reads. Run `php artisan schedule:run` from cron every minute,
// or `php artisan storefront:prune` hourly.
Schedule::command('storefront:prune')->hourly();
