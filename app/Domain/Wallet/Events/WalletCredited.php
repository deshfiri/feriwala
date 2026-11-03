<?php

namespace App\Domain\Wallet\Events;

/**
 * Money arrived in an account's Wallet and the posting that brought it has
 * committed (D36).
 *
 * Dispatched after commit, never inside the posting, so a listener sees the new
 * balance and can never hold the posting open. It carries ids and nothing else —
 * no amount, no description — and may be delivered more than once: whatever
 * listens must be idempotent. Nothing is dispatched for a conversion between the
 * Wallet's own kinds of money, because no value arrived.
 */
final readonly class WalletCredited
{
    public function __construct(
        public int $walletId,
        public int $businessAccountId,
        public int $walletTransactionId,
    ) {}
}
