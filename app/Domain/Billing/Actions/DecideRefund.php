<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Billing\RefundabilityPolicy;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * An administrator's answer to a refund request (D17).
 *
 * Approve and reject are one action with two outcomes, unlike the activation
 * decisions of D22 — because here they mean the same thing to the account
 * holder's money (nothing has moved yet) and differ only in what follows.
 * Approval is not payment: {@see RefundStatus} keeps `Approved` and `Processed`
 * apart so a gateway refund that fails cannot look like money that went back.
 *
 * Eligibility is **re-checked at the moment of approval**. Between the request
 * and the decision the account can be activated, and a package fee that was
 * refundable on Monday is not on Wednesday. Trusting the request would pay out
 * against a rule that had already stopped applying.
 */
class DecideRefund
{
    public function __construct(
        protected RefundabilityPolicy $policy,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function approve(RefundRequest $request, User $by, ?string $note = null): RefundRequest
    {
        return $this->database->transaction(function () use ($request, $by, $note) {
            $locked = $this->lock($request);

            // Re-checked here, inside the lock, against the state as it is now.
            $eligibility = $this->policy->evaluate(
                $locked->payment()->with('allocations')->firstOrFail(),
                $locked->allocation_type,
            );

            if (! $eligibility->isRefundable) {
                throw new InvalidArgumentException(
                    $eligibility->reason ?? 'This can no longer be refunded.'
                );
            }

            return $this->settle($locked, RefundStatus::Approved, $by, $note, 'billing.refund_approved');
        });
    }

    /**
     * @throws InvalidArgumentException
     */
    public function reject(RefundRequest $request, User $by, string $note): RefundRequest
    {
        if (trim($note) === '') {
            // A refusal with no explanation is the one a chargeback dispute
            // cannot be defended with.
            throw new InvalidArgumentException('A rejection needs a reason.');
        }

        return $this->database->transaction(
            fn () => $this->settle(
                $this->lock($request),
                RefundStatus::Rejected,
                $by,
                $note,
                'billing.refund_rejected',
            )
        );
    }

    protected function lock(RefundRequest $request): RefundRequest
    {
        /** @var RefundRequest $locked */
        $locked = RefundRequest::query()->lockForUpdate()->findOrFail($request->id);

        if (! $locked->isOpen()) {
            // Two administrators deciding at once: one wins, and the loser is
            // told rather than silently overwriting a decision already taken.
            throw new InvalidArgumentException(
                'This refund has already been decided.'
            );
        }

        return $locked;
    }

    protected function settle(
        RefundRequest $request,
        RefundStatus $to,
        User $by,
        ?string $note,
        string $action,
    ): RefundRequest {
        $from = $request->status;

        $request->transitionTo($to);

        $request->forceFill([
            'decided_by' => $by->id,
            'decided_at' => now(),
            'decision_note' => $note === null ? null : trim($note),
        ])->save();

        /*
         * D17 requires every refund decision in the financial ledger as well as
         * the audit log. The audit entry is written here; the ledger posting
         * attaches when the ledger lands (Phase 2) — an approval moves no money
         * on its own, so nothing is unrecorded in the meantime.
         */
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $by->id,
            auditableType: RefundRequest::class,
            auditableId: $request->id,
            before: ['status' => $from->value],
            after: [
                'status' => $to->value,
                'amount_minor' => $request->amount_minor->minorUnits,
                'allocation_type' => $request->allocation_type->value,
            ],
            reason: $request->reason,
            note: $request->decision_note,
            accountId: $request->business_account_id,
            module: 'billing',
        ));

        return $request;
    }
}
