<?php

namespace App\Domain\Notification\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\OrderReturn;
use App\Models\User;
use App\Notifications\Cases\CaseOutcome;
use App\Notifications\Staff\CaseStepRequired;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Tells the right people what a return, exchange or damage has just become (D35).
 *
 * **Two audiences, never mixed.** *Staff* are told a step is waiting for them —
 * and only the staff who hold the permission to take it (inspect, decide who
 * bears the loss, post it, send the item), plus Super Admins, and only people with
 * platform access: a business identity is never a recipient. *The Partner* — the
 * owner of the account the case belongs to, and nobody else — is told what
 * happened to their own case. A notification carries the outcome, the kind of
 * case, its reference and its public id; the wording is made in the reader's
 * language when they open the bell, and the link goes to a page that resolves the
 * case through the reader's own authority.
 *
 * **Safe to repeat.** Every notification's id is derived from the case, the step
 * or outcome and the recipient, so a retried job or a case that reaches the same
 * stage twice writes one bell entry. It runs after the surrounding transaction
 * commits, and a failure to notify is reported and swallowed: the decision it
 * announces has already been made and is never undone by a missed nudge.
 */
class AnnounceCaseEvent
{
    public function __construct(protected NotifyStaffOfSubmission $staff) {}

    /**
     * A case has moved to `$to` — said to whoever has to act next, and to the Partner.
     */
    public function returnMoved(OrderReturn $case, ReturnStatus $to, OrderStatusChangeSource $source): void
    {
        // A return from before the case workflow keeps the notifications it always had.
        if (! $case->usesCaseWorkflow()) {
            return;
        }

        $kind = $case->isExchange() ? 'exchange' : 'return';
        $caseId = $case->public_id;
        $reference = $case->reference;
        $accountId = $case->business_account_id;
        $partnerLiable = $case->responsible_party === ReturnParty::Partner;
        $refunded = $case->refund_state === ReturnRefundState::Completed;

        $this->afterCommit(function () use ($to, $source, $kind, $caseId, $reference, $accountId, $partnerLiable, $refunded) {
            match ($to) {
                ReturnStatus::Cancelled => $source === OrderStatusChangeSource::Staff
                    ? $this->tellPartner($accountId, 'request_cancelled_by_staff', $kind, $reference, $caseId)
                    : null,
                ReturnStatus::ParcelReceived => $this->received($accountId, $kind, $reference, $caseId, PermissionModule::ReturnCase),
                ReturnStatus::InspectionApproved, ReturnStatus::InspectionRejected => $this->tellPartner($accountId, 'inspection_completed', $kind, $reference, $caseId),
                ReturnStatus::SettlementPending => $this->settled($accountId, $kind, $reference, $caseId, $partnerLiable),
                ReturnStatus::Completed => $refunded ? $this->tellPartner($accountId, 'refund_posted', $kind, $reference, $caseId) : null,
                default => null,
            };
        });
    }

    /**
     * A damage has moved to `$to`.
     */
    public function damageMoved(OrderDamage $damage, DamageStatus $to, OrderStatusChangeSource $source): void
    {
        $caseId = $damage->public_id;
        $reference = $damage->reference;
        $accountId = $damage->business_account_id;
        $partnerLiable = $damage->responsible_party === ReturnParty::Partner;

        $this->afterCommit(function () use ($to, $source, $caseId, $reference, $accountId, $partnerLiable) {
            match ($to) {
                DamageStatus::Received => $this->tellStaff('inspect', 'damage', $reference, $caseId, PermissionModule::Damage, PermissionAction::Inspect),
                DamageStatus::LiabilityReview => $this->tellStaff('assign_liability', 'damage', $reference, $caseId, PermissionModule::Damage, PermissionAction::AssignLiability),
                DamageStatus::Approved => $this->tellPartner($accountId, 'responsibility_finalized', 'damage', $reference, $caseId),
                DamageStatus::Rejected => $this->tellPartner($accountId, 'request_rejected', 'damage', $reference, $caseId),
                DamageStatus::FinancialPostingPending => $this->tellStaff('finalize', 'damage', $reference, $caseId, PermissionModule::Damage, PermissionAction::Finalize),
                DamageStatus::Finalized => $this->finalized($accountId, $reference, $caseId, $partnerLiable),
                DamageStatus::DispatchRequested => $this->deliveryRequested($accountId, $reference, $caseId),
                DamageStatus::Dispatched => $this->tellPartner($accountId, 'damage_dispatched', 'damage', $reference, $caseId),
                DamageStatus::ReceivedByPartner => $source === OrderStatusChangeSource::Staff
                    ? $this->tellPartner($accountId, 'damage_received', 'damage', $reference, $caseId)
                    : null,
                default => null,
            };
        });
    }

    /**
     * Who bears a case was put right: the Partner is told, once per correction.
     */
    public function responsibilityCorrected(OrderReturn|OrderDamage $case, int $revision): void
    {
        $kind = $case instanceof OrderDamage ? 'damage' : ($case->isExchange() ? 'exchange' : 'return');
        $accountId = $case->business_account_id;
        $reference = $case->reference;
        $caseId = $case->public_id;

        $this->afterCommit(fn () => $this->tellPartner($accountId, 'responsibility_corrected', $kind, $reference, $caseId, (string) $revision));
    }

    /**
     * The replacement of an exchange was released to fulfilment, or has been sent.
     */
    public function exchangeOrder(OrderReturn $case, string $outcome): void
    {
        $accountId = $case->business_account_id;
        $reference = $case->exchange_number ?? $case->reference;
        $caseId = $case->public_id;

        $this->afterCommit(fn () => $this->tellPartner($accountId, $outcome, 'exchange', (string) $reference, $caseId));
    }

    protected function received(int $accountId, string $kind, string $reference, string $caseId, PermissionModule $module): void
    {
        $this->tellPartner($accountId, 'parcel_received', $kind, $reference, $caseId);
        $this->tellStaff('inspect', $kind, $reference, $caseId, $module, PermissionAction::Inspect);
    }

    protected function settled(int $accountId, string $kind, string $reference, string $caseId, bool $partnerLiable): void
    {
        $this->tellPartner($accountId, 'responsibility_finalized', $kind, $reference, $caseId);

        if ($partnerLiable) {
            $this->tellPartner($accountId, 'charge_posted', $kind, $reference, $caseId);
        }
    }

    protected function finalized(int $accountId, string $reference, string $caseId, bool $partnerLiable): void
    {
        $this->tellPartner($accountId, 'damage_finalized', 'damage', $reference, $caseId);

        if ($partnerLiable) {
            $this->tellPartner($accountId, 'charge_posted', 'damage', $reference, $caseId);
        }
    }

    protected function deliveryRequested(int $accountId, string $reference, string $caseId): void
    {
        $this->tellPartner($accountId, 'delivery_accepted', 'damage', $reference, $caseId);
        $this->tellStaff('dispatch', 'damage', $reference, $caseId, PermissionModule::Damage, PermissionAction::Dispatch);
    }

    /**
     * The staff who may take `$action` on this kind of case — and Super Admins.
     */
    protected function tellStaff(string $step, string $kind, string $reference, string $caseId, PermissionModule $module, PermissionAction $action): void
    {
        try {
            foreach ($this->staff->holdersOf(PermissionCatalogue::name($module, $action)) as $recipient) {
                $notification = new CaseStepRequired($step, $kind, $reference, $caseId);
                $notification->id = $this->idFor("case.step.{$step}", $caseId, $recipient);

                Notification::send($recipient, $notification);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The Partner who owns the case — the account's owner, and no one else.
     */
    protected function tellPartner(int $accountId, string $outcome, string $kind, string $reference, string $caseId, string $revision = ''): void
    {
        try {
            $owner = BusinessAccount::query()->find($accountId)?->owner;

            if (! $owner instanceof User) {
                return;
            }

            $notification = new CaseOutcome($outcome, $kind, $reference, $caseId);
            $notification->id = $this->idFor("case.outcome.{$outcome}", $caseId.($revision === '' ? '' : ":{$revision}"), $owner);

            Notification::send($owner, $notification);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    protected function idFor(string $event, string $subject, User $recipient): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "feriwala:{$event}:{$subject}:{$recipient->public_id}")->toString();
    }

    /**
     * After the surrounding transaction commits — or now, outside one. Never inside
     * it, so a rolled-back step announces nothing.
     */
    protected function afterCommit(callable $announce): void
    {
        DB::afterCommit(function () use ($announce) {
            try {
                $announce();
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
