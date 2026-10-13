<?php

namespace App\Domain\Website\Jobs;

use App\Domain\Website\Actions\AnnouncePaymentMethodsChanged as Announce;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The fan-out of a payment-method change to storefronts, off the admin's
 * request (P2 D2). Each website gets at most one pending `website.updated`
 * however often this runs, so a retried job tells nobody twice.
 */
class AnnouncePaymentMethodsChanged implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  list<string>  $currencies
     */
    public function __construct(
        public readonly array $currencies,
    ) {
        $this->onQueue((string) config('website.webhooks.queue', 'webhooks'));
    }

    public function handle(Announce $announce): void
    {
        $announce->handle($this->currencies);
    }
}
