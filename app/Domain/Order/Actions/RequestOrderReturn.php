<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Queries\ReturnEligibility;
use App\Models\User;
use App\Support\Concurrency\DistributedLock;
use App\Support\Concurrency\Exceptions\LockTimeout;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Ask for goods to be taken back (§18.2, contract §6.3, P6-12).
 *
 * **A request, and only a request.** Nothing moves: no stock, no money, no
 * order status beyond saying a return was asked for. Approval, receipt,
 * disposition and refund are separate decisions taken by the people who own
 * them, which is what §6.3 of the contract promises a storefront.
 *
 * What is asked for is checked against the order, never taken on trust: the
 * order must be in a state something can come back from, the window must still
 * be open, every line must be one this order sold, and no more of a line may
 * come back than went out — counting what earlier returns already claim.
 *
 * The same request twice is one return. An idempotency key makes a retry
 * return the first answer, and the order is locked while the quantities are
 * read and written so two requests arriving together cannot each see the last
 * unit as free.
 */
class RequestOrderReturn
{
    public function __construct(
        protected ReturnEligibility $eligibility,
        protected AnnounceReturnStatus $announcements,
        protected DatabaseManager $database,
        protected DistributedLock $lock,
    ) {}

    /**
     * @return array{0: OrderReturn, 1: bool} the return, and whether this call created it
     *
     * @throws ReturnRefused
     */
    public function handle(
        Order $order,
        ReturnSubmission $submission,
        OrderStatusChangeSource $source,
        ?User $actor = null,
    ): array {
        if ($submission->quantities() === []) {
            throw ReturnRefused::noLines();
        }

        if ($existing = $this->existingFor($order, $submission->idempotencyKey)) {
            return [$existing, false];
        }

        try {
            return $this->lock->run(
                key: 'order-return:'.$order->id,
                callback: fn () => [$this->write($order, $submission, $source, $actor), true],
                ttlSeconds: 20,
                waitSeconds: 10,
            );
        } catch (LockTimeout) {
            throw ReturnRefused::busy();
        } catch (UniqueConstraintViolationException $exception) {
            // A racing retry of the same key won. Its return is the answer.
            $existing = $this->existingFor($order, $submission->idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            return [$existing, false];
        }
    }

    /**
     * @throws ReturnRefused
     */
    protected function write(Order $order, ReturnSubmission $submission, OrderStatusChangeSource $source, ?User $actor): OrderReturn
    {
        return $this->database->transaction(function () use ($order, $submission, $source, $actor) {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $locked->load(['items', 'statusHistory']);

            $this->eligibility->check($locked);

            $lines = $this->resolve($locked, $submission);

            $return = OrderReturn::create([
                'order_id' => $locked->id,
                'business_account_id' => $locked->business_account_id,
                'website_id' => $locked->website_id,
                'source' => $source,
                'status' => ReturnStatus::Requested,
                'reason' => $submission->reason,
                'customer_note' => $submission->customerNote,
                'evidence' => $submission->evidence,
                'requested_by' => $actor?->id,
                'requested_at' => CarbonImmutable::now(),
                'currency_code' => $locked->currency_code,
                'idempotency_key' => $submission->idempotencyKey,
            ]);

            foreach ($lines as [$item, $quantity]) {
                $return->items()->create([
                    'order_item_id' => $item->id,
                    'quantity' => $quantity,
                    'currency_code' => $item->currency_code,
                ]);
            }

            $return->recordRequest(
                new StatusChange(
                    actorId: $actor?->id,
                    reason: 'Return asked for: '.$submission->reason->label().'.',
                    publicNote: 'returns.notes.requested',
                ),
                $source,
            );

            $this->announcements->handle($return->refresh()->load('items'));

            return $return;
        });
    }

    /**
     * Turn what was asked for into lines of this order, or refuse.
     *
     * @return array<int, array{0: OrderItem, 1: int}>
     *
     * @throws ReturnRefused
     */
    protected function resolve(Order $order, ReturnSubmission $submission): array
    {
        $report = $this->eligibility->for($order);
        $returnable = [];

        foreach ($report['lines'] as $line) {
            $returnable[$line['sku']] = $line['returnable'];
        }

        $lines = [];

        foreach ($submission->quantities() as $sku => $quantity) {
            /** @var OrderItem|null $item */
            $item = $order->items->firstWhere('sku', $sku);

            if ($item === null) {
                throw ReturnRefused::lineNotOnOrder($sku);
            }

            if ($quantity > ($returnable[$sku] ?? 0)) {
                throw ReturnRefused::lineNotReturnable($sku, $quantity, $returnable[$sku] ?? 0);
            }

            $lines[] = [$item, $quantity];
        }

        return $lines;
    }

    protected function existingFor(Order $order, ?string $key): ?OrderReturn
    {
        if ($key === null) {
            return null;
        }

        /** @var OrderReturn|null $return */
        $return = OrderReturn::query()
            ->where('order_id', $order->id)
            ->where('idempotency_key', $key)
            ->with('items')
            ->first();

        return $return;
    }
}
