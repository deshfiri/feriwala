<?php

namespace App\Console\Commands;

use App\Domain\Billing\OrderDeliveryCharges;
use Illuminate\Console\Command;

/**
 * `php artisan delivery-charges:check` — the deployment readiness check for
 * customer delivery (D37). Fails while the Inside Dhaka or Outside Dhaka rate
 * is missing, because customer checkout refuses every order to an area with no
 * rate. Reads only; changes nothing.
 */
class CheckDeliveryCharges extends Command
{
    protected $signature = 'delivery-charges:check';

    protected $description = 'Fail while the Inside Dhaka or Outside Dhaka customer delivery charge is not configured';

    public function handle(OrderDeliveryCharges $charges): int
    {
        $missing = $charges->missingRates();

        if ($missing !== []) {
            $this->components->error(OrderDeliveryCharges::NOT_CONFIGURED_MESSAGE);

            foreach ($missing as $area) {
                $this->components->bulletList([$area->label().' has no rate.']);
            }

            return self::FAILURE;
        }

        $this->components->info('Ready: both customer delivery charges (Inside Dhaka and Outside Dhaka) are configured.');

        return self::SUCCESS;
    }
}
