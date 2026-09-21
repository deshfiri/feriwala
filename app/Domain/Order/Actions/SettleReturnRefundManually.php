<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Data\SensitiveActionRequest;
use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\SensitiveActionGuard;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\OrderReturn;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * A person recording that they settled a return's refund by hand (§26.3, §28,
 * P6-12).
 *
 * For a refund nothing here can send: a cash-on-delivery order, a payment that
 * never settled, or one an administrator refused through the gateway. The
 * person says how they settled it — cash, a transfer, whatever it was — and
 * that statement is the record. **This moves no money and posts nothing to a
 * wallet or the ledger**: it records that money moved outside the system, with
 * who said so and how, which is the most the system can truthfully know.
 *
 * Guarded like any other reversal of money (`payment.reverse_transaction`,
 * confirmed password, two-factor), because "I refunded this" is exactly the
 * claim somebody would make falsely.
 */
class SettleReturnRefundManually
{
    public const MINIMUM_NOTE = 10;

    public function __construct(
        protected SensitiveActionGuard $guard,
        protected AnnounceReturnStatus $announcements,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws ReturnRefused
     */
    public function handle(User $actor, OrderReturn $return, string $how, bool $passwordConfirmed, bool $twoFactorEnabled): OrderReturn
    {
        $how = trim($how);

        if (mb_strlen($how) < self::MINIMUM_NOTE) {
            throw ReturnRefused::decisionNeedsAReason();
        }

        $this->guard->authorize($actor, new SensitiveActionRequest(
            module: PermissionModule::Payment,
            action: PermissionAction::ReverseTransaction,
            reason: $how,
            passwordConfirmed: $passwordConfirmed,
            twoFactorEnabled: $twoFactorEnabled,
        ));

        $recorded = false;

        $settled = $this->database->transaction(function () use ($actor, $return, $how, &$recorded) {
            /** @var OrderReturn $locked */
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);

            // Recorded once already: the first statement stands.
            if ($locked->refund_state === ReturnRefundState::Completed) {
                return $locked;
            }

            if ($locked->refund_state !== ReturnRefundState::ManualReview || $locked->status !== ReturnStatus::Received) {
                throw ReturnRefused::notAwaitingManualRefund();
            }

            $locked->forceFill([
                'refund_state' => ReturnRefundState::Completed,
                'refund_note' => $how,
                'refunded_at' => CarbonImmutable::now(),
            ]);

            $locked->moveTo(
                ReturnStatus::Refunded,
                new StatusChange(
                    actorId: $actor->id,
                    reason: 'Refund settled by hand.',
                    internalNote: $how,
                    publicNote: 'returns.notes.refunded',
                ),
                OrderStatusChangeSource::Staff,
            );

            $this->announcements->handle($locked->refresh()->load('items'));
            $recorded = true;

            return $locked;
        });

        $return->setRawAttributes($settled->getAttributes(), sync: true);

        // A repeat changed nothing, and says nothing.
        if (! $recorded) {
            return $settled;
        }

        $this->audit->handle(new AuditEntry(
            action: 'order_return.refund_settled_manually',
            actorId: $actor->id,
            auditableType: OrderReturn::class,
            auditableId: $settled->id,
            after: [
                'reference' => $settled->reference,
                'amount_minor' => $settled->refund_amount_minor?->minorUnits,
                'currency' => $settled->currency_code,
            ],
            reason: $how,
            accountId: $settled->business_account_id,
            module: PermissionModule::Payment->value,
            isSensitive: true,
        ));

        return $settled;
    }
}
