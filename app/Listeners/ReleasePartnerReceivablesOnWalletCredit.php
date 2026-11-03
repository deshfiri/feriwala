<?php

namespace App\Listeners;

use App\Domain\Order\Actions\ReleaseCollectableReceivables;
use App\Domain\Wallet\Events\WalletCredited;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * Money arrived in a Partner's Wallet: release the orders waiting for it, oldest
 * first (D36).
 *
 * The same credit can be announced more than once; collecting again finds nothing
 * left to do. A failure never reaches the posting that caused it — the credit is
 * committed — and the scheduled sweep tries again.
 */
class ReleasePartnerReceivablesOnWalletCredit
{
    public function __construct(
        protected ReleaseCollectableReceivables $release,
        protected LogManager $log,
    ) {}

    public function handle(WalletCredited $event): void
    {
        try {
            $this->release->forAccount($event->businessAccountId);
        } catch (Throwable $throwable) {
            $this->log->channel('payment')->critical('Could not release Partner-gateway receivables after a Wallet credit', [
                'business_account_id' => $event->businessAccountId,
                'wallet_transaction_id' => $event->walletTransactionId,
                'error' => $throwable::class,
            ]);
        }
    }
}
