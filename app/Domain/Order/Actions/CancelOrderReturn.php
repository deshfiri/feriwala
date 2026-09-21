<?php

namespace App\Domain\Order\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\OrderReturn;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Call a return off before any goods have come back (§18.2, P6-12).
 *
 * The customer through their shop, the partner, or staff. Only while nothing
 * has arrived: once goods are in and counted, what remains is the money, and
 * that is settled by the refund rather than by pretending the goods never came.
 *
 * Cancelling gives up the return's claim on the order's lines, so the same
 * units may be asked for again — which is what a customer who withdrew a
 * request by mistake needs.
 */
class CancelOrderReturn
{
    public function __construct(
        protected AnnounceReturnStatus $announcements,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @return bool whether this call cancelled it; false when it already was
     *
     * @throws ReturnRefused when goods have already arrived, or it was decided otherwise
     */
    public function handle(OrderReturn $return, OrderStatusChangeSource $source, ?User $actor = null, ?string $reason = null): bool
    {
        $cancelled = $this->database->transaction(function () use ($return, $source, $actor, $reason) {
            /** @var OrderReturn $locked */
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);

            // Asked twice: the first one did it.
            if ($locked->status === ReturnStatus::Cancelled) {
                return false;
            }

            if (! $locked->status->isOpen()) {
                throw ReturnRefused::notInThatState();
            }

            $locked->forceFill(['cancelled_at' => CarbonImmutable::now()]);

            $locked->moveTo(
                ReturnStatus::Cancelled,
                new StatusChange(
                    actorId: $actor?->id,
                    reason: $reason ?? 'The return was withdrawn.',
                    publicNote: 'returns.notes.cancelled',
                ),
                $source,
            );

            $this->announcements->handle($locked->refresh()->load('items'));

            $return->setRawAttributes($locked->getAttributes(), sync: true);

            return true;
        });

        if ($cancelled && $actor !== null) {
            $this->audit->handle(new AuditEntry(
                action: 'order_return.cancelled',
                actorId: $actor->id,
                auditableType: OrderReturn::class,
                auditableId: $return->id,
                after: ['status' => ReturnStatus::Cancelled->value, 'reference' => $return->reference],
                reason: $reason,
                accountId: $return->business_account_id,
                module: 'order',
            ));
        }

        return $cancelled;
    }
}
