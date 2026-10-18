<?php

namespace App\Console\Commands;

use App\Domain\Website\Actions\ListWholeCatalogueOnWebsite;
use Illuminate\Console\Command;

/**
 * List the whole eligible catalogue on every Non-Conditional storefront now,
 * instead of waiting for the scheduled pass. Safe to run again: it only adds.
 */
class ListWholeCatalogueCommand extends Command
{
    protected $signature = 'website:list-catalogue';

    protected $description = 'List the whole eligible catalogue on Non-Conditional storefronts now';

    public function handle(ListWholeCatalogueOnWebsite $listing): int
    {
        $this->info('Listed '.$listing->sweep().' product(s).');

        return self::SUCCESS;
    }
}
