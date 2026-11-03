<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\ReceivableCollectionStatus;
use App\Domain\Order\Models\OrderPlatformReceivable;
use App\Domain\Order\Models\OrderReceivableCollection;
use App\Support\Concurrency\DistributedLock;
use Illuminate\Support\Collection;

/**
 * Takes what Banij is owed from a Partner's Wallet, oldest receivable first (D36).
 *
 * The one entry point for every trigger — a Partner payment being verified, money
 * arriving in the Wallet, and the sweep that repairs a missed event or closes an
 * overdue hold — so they all collect in the same order and a newer order can
 * never overtake an older one:
 *
 *   - one account at a time, under the account's collection lock;
 *   - active receivables by the time their payment was verified, then by id;
 *   - one receivable at a time, and each needs its **whole** amount;
 *   - when an older one is still waiting for money, **nothing newer is debited**.
 *     A smaller receivable the Wallet could pay is not taken ahead of it — the
 *     older order was paid first and waits first. The newer ones are still
 *     secured and held to their own deadlines, so none of them loses its stock
 *     while queued;
 *   - a receivable that is no longer active (collected, expired, or sent to staff
 *     because its stock could not be secured) is not "waiting for money" and does
 *     not stop the next one. A stock problem is staff's to solve and must not
 *     freeze every newer order behind it.
 *
 * Idempotent: running it again after a repeated event finds nothing left to do.
 */
class ReleaseCollectableReceivables
{
    public function __construct(
        protected CollectPartnerReceivable $collect,
        protected OpenReceivableCollection $open,
        protected DistributedLock $locks,
    ) {}

    /**
     * A Partner payment was verified: open its collection and collect in order.
     */
    public function forPayment(Payment $payment): void
    {
        $collection = $this->open->handle($payment);

        if ($collection !== null) {
            $this->forAccount($collection->business_account_id);
        }
    }

    /**
     * @return int how many receivables this run collected
     */
    public function forAccount(int $accountId): int
    {
        return $this->locks->run('partner-receivable:account:'.$accountId, function () use ($accountId): int {
            $collected = 0;
            $waitingForMoney = false;

            $ids = OrderReceivableCollection::query()->active()->where('business_account_id', $accountId)->pluck('id');

            foreach ($ids as $id) {
                // Behind an older receivable that is still short, a newer one is held
                // like it but is not debited, whatever the Wallet could cover.
                $result = $this->collect->attempt((int) $id, mayDebit: ! $waitingForMoney);

                if ($result->status === ReceivableCollectionStatus::Collected) {
                    $collected++;
                }

                if ($result->status === ReceivableCollectionStatus::WalletInsufficient) {
                    $waitingForMoney = true;
                }
            }

            return $collected;
        }, ttlSeconds: 120, waitSeconds: 60);
    }

    /**
     * The scheduled repair: open the collection of any verified Partner payment
     * whose event was missed, then work every account that has one waiting — which
     * also closes the holds whose deadline has passed.
     *
     * @return int how many receivables were collected
     */
    public function sweep(): int
    {
        $this->openMissed();

        $collected = 0;

        /** @var Collection<int, int> $accounts */
        $accounts = OrderReceivableCollection::query()->active()->reorder()->distinct()->pluck('business_account_id');

        foreach ($accounts as $accountId) {
            $collected += $this->forAccount((int) $accountId);
        }

        return $collected;
    }

    protected function openMissed(): void
    {
        $settled = array_map(
            static fn (PaymentStatus $status): string => $status->value,
            array_filter(PaymentStatus::cases(), static fn (PaymentStatus $status): bool => $status->isSettled()),
        );

        OrderPlatformReceivable::query()
            ->whereDoesntHave('collection')
            // Through the order: the receivable is frozen before the order's payment exists.
            ->whereHas('order', fn ($order) => $order
                ->where('status', OrderStatus::PaymentPending->value)
                ->whereHas('payment', fn ($payment) => $payment->whereIn('status', $settled)))
            ->with('order.payment')
            ->orderBy('id')
            ->each(function (OrderPlatformReceivable $receivable): void {
                if ($receivable->order->payment !== null) {
                    $this->open->handle($receivable->order->payment);
                }
            });
    }
}
