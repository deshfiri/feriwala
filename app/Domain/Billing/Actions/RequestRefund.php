<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Billing\RefundabilityPolicy;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Opens a refund request against one component of a payment (D17).
 *
 * Asking is not getting. This records that a refund was sought and on what
 * grounds; {@see DecideRefund} is where an administrator answers. D17 makes
 * every refund an administrative decision, so there is deliberately no path
 * that both requests and grants in one step.
 *
 * Eligibility is checked **inside** the transaction that writes the row, and the
 * rule that applied is copied onto it — a policy edited between the check and
 * the write would otherwise leave a request standing on grounds that no longer
 * exist, and nobody would be able to tell which rule it was judged under.
 */
class RequestRefund
{
    public function __construct(
        protected RefundabilityPolicy $policy,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws InvalidArgumentException when the component may not be refunded
     */
    public function handle(
        Payment $payment,
        AllocationType $type,
        User $requestedBy,
        string $reason,
    ): RefundRequest {
        if (trim($reason) === '') {
            // A refund with no stated grounds is one nobody can defend to an
            // auditor or a customer.
            throw new InvalidArgumentException('A refund request needs a reason.');
        }

        return $this->database->transaction(function () use ($payment, $type, $requestedBy, $reason) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $locked->load('allocations');

            $eligibility = $this->policy->evaluate($locked, $type);

            if (! $eligibility->isRefundable) {
                throw new InvalidArgumentException(
                    $eligibility->reason ?? 'This cannot be refunded.'
                );
            }

            try {
                $request = RefundRequest::create([
                    'payment_id' => $locked->id,
                    'business_account_id' => $locked->business_account_id,
                    'allocation_type' => $type,
                    'amount_minor' => $eligibility->refundableAmount,
                    'currency_code' => $eligibility->refundableAmount->currency->value,
                    'refundability' => $eligibility->rule,
                    'status' => RefundStatus::Requested,
                    'reason' => trim($reason),
                    'requested_by' => $requestedBy->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The partial index on open requests. Two administrators asked
                // at the same moment; without it the account would be owed the
                // money twice.
                throw new InvalidArgumentException(
                    'A refund of this charge is already awaiting a decision.'
                );
            }

            $this->audit->handle(new AuditEntry(
                action: 'billing.refund_requested',
                actorId: $requestedBy->id,
                auditableType: RefundRequest::class,
                auditableId: $request->id,
                after: [
                    'allocation_type' => $type->value,
                    'amount_minor' => $eligibility->refundableAmount->minorUnits,
                    'refundability' => $eligibility->rule->value,
                ],
                reason: $request->reason,
                accountId: $locked->business_account_id,
                module: 'billing',
            ));

            return $request;
        });
    }
}
