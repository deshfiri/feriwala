<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Order\Actions\AdvanceOrderFulfilmentStatus;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Supplier\Enums\FulfilmentCommitmentStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Notifications\Supplier\SupplierFulfilmentCommitmentExpired;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The scheduled sweep over Supplier fulfilment commitments nobody confirmed
 * in time (Advanced Order Management batch, Commit 2).
 *
 * A Supplier who declines or simply never answers must not corrupt the
 * order, its payable or its inventory state: the commitment is cancelled
 * (freeing the capacity it held, the same release every other terminal move
 * already gives back), its pending payable is cancelled with it, the
 * allocation is marked {@see AllocationStatus::Released} — released, not
 * superseded, since nothing replaces it yet — and the line's fulfilment
 * status returns to {@see OrderFulfillmentStatus::SourceAllocationPending}
 * for staff to choose again. Every move is `System`-sourced; there is no
 * human actor behind a scheduled sweep.
 *
 * Idempotent and safe to re-run: each commitment is re-read and re-checked
 * under its own lock, so a second pass or a confirmation racing the sweep
 * finds nothing left to expire.
 */
class ExpireOverdueFulfilmentCommitments
{
    public function __construct(
        protected AdvanceSupplierFulfilmentCommitment $advance,
        protected CancelSupplierPayable $cancelPayable,
        protected AdvanceOrderFulfilmentStatus $advanceFulfilment,
        protected DatabaseManager $database,
        protected LogManager $log,
    ) {}

    public function handle(): int
    {
        $expired = 0;

        SupplierFulfilmentCommitment::query()
            ->where('status', FulfilmentCommitmentStatus::AwaitingConfirmation->value)
            ->whereNotNull('confirmation_due_at')
            ->where('confirmation_due_at', '<=', CarbonImmutable::now())
            ->orderBy('id')
            ->chunkById(100, function ($commitments) use (&$expired) {
                foreach ($commitments as $commitment) {
                    try {
                        if ($this->expireOne($commitment)) {
                            $expired++;
                        }
                    } catch (Throwable $exception) {
                        $this->log->channel('supplier')->error('Could not expire an overdue fulfilment commitment', [
                            'commitment' => $commitment->reference,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        return $expired;
    }

    protected function expireOne(SupplierFulfilmentCommitment $commitment): bool
    {
        return $this->database->transaction(function () use ($commitment) {
            /** @var SupplierFulfilmentCommitment|null $locked */
            $locked = SupplierFulfilmentCommitment::query()->lockForUpdate()->find($commitment->id);

            if ($locked === null
                || $locked->status !== FulfilmentCommitmentStatus::AwaitingConfirmation
                || $locked->confirmation_due_at === null
                || $locked->confirmation_due_at->isAfter(CarbonImmutable::now())) {
                return false;
            }

            $reason = 'The Supplier confirmation deadline passed without a response.';

            $this->advance->cancel($locked, null, $reason, SupplierStatusChangeSource::System);

            /** @var OrderItemAllocation $allocation */
            $allocation = $locked->allocation;

            if ($allocation->payable !== null) {
                $this->cancelPayable->cancelOne($allocation->payable, $reason);
            }

            $allocation->forceFill([
                'status' => AllocationStatus::Released,
                'released_by' => null,
                'released_at' => now(),
                'release_reason' => $reason,
            ])->save();

            $order = $allocation->order;

            if ($order->canTransitionTo(OrderFulfillmentStatus::SourceAllocationPending, 'fulfillment_status')) {
                $this->advanceFulfilment->handle(
                    $order,
                    OrderFulfillmentStatus::SourceAllocationPending,
                    null,
                    $reason,
                    OrderStatusChangeSource::System,
                );
            }

            Notification::send($commitment->offer->supplier, new SupplierFulfilmentCommitmentExpired($commitment));

            return true;
        });
    }
}
