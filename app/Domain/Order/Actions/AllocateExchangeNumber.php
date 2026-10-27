<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * Hand out the next exchange number of an original order (D35).
 *
 * `EX-{original number}` for the first exchange, then `-2`, `-3`, … for every
 * further one — counting **every** exchange ever asked for against that
 * original, including ones that were refused or cancelled, because a number that
 * was shown to somebody is never given to another case.
 *
 * Handed out under a row lock on the **original** order, so two requests for it
 * arriving together are made to take turns and cannot both read the same last
 * number. The unique index on `(original_order_id, exchange_sequence)` and on
 * the display number is the guarantee underneath: it is what makes a duplicate
 * impossible if the lock were ever bypassed. The case's internal id is its own,
 * and is never the number.
 *
 * The original is always the root. A second exchange, of the replacement the
 * first one sent, is still an exchange *of the original* — `EX-ORD-…-2` — and
 * remembers the case it follows.
 *
 * Must be called inside the caller's transaction, so the lock is held until the
 * case that uses the number has been written.
 */
class AllocateExchangeNumber
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * @return array{root: Order, sequence: int, number: string, previous: OrderReturn|null}
     */
    public function handle(Order $source): array
    {
        if ($this->database->transactionLevel() === 0) {
            throw new LogicException('An exchange number is allocated inside the transaction that uses it.');
        }

        $rootId = $source->isExchange() ? (int) $source->original_order_id : $source->id;

        /** @var Order $root */
        $root = Order::query()->lockForUpdate()->findOrFail($rootId);

        $sequence = (int) OrderReturn::query()->where('original_order_id', $root->id)->max('exchange_sequence') + 1;

        $previous = $source->isExchange() && $source->exchange_return_id !== null
            ? OrderReturn::query()->find($source->exchange_return_id)
            : null;

        return [
            'root' => $root,
            'sequence' => $sequence,
            'number' => 'EX-'.$root->reference.($sequence > 1 ? '-'.$sequence : ''),
            'previous' => $previous,
        ];
    }
}
