<?php

namespace App\Domain\Damage\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Damage\Actions\Concerns\GuardsDamageActions;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Exceptions\DamageRefused;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Damage\Queries\DamageValuation;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnParty;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * Decide who bears a damage and how much it costs (D35): the liability review.
 *
 * Only after inspection, and only by someone who may assign liability. It records
 * three things together, none of which can be assumed:
 *
 *   - **Who bears it** — Banij or the Partner — with the reason. The first row of
 *     the case's responsibility history; every later change adds to it and never
 *     rewrites it.
 *   - **How much**, which is at most what the damaged units were worth when they
 *     were sold ({@see DamageValuation}, from the order line's own snapshot — never
 *     the Partner's price, never today's), and in the order's currency. Staff may
 *     approve less. Never a negative figure, and never zero without saying why: a
 *     loss of nothing is an explicit write-off.
 *   - The case moves to **financial posting pending**. Nothing is posted here —
 *     posting is {@see FinalizeDamage}, a separate permission — and the approved
 *     loss is fixed by the database once decided: after this only a compensating
 *     entry changes what it cost.
 */
class ApproveDamageLiability
{
    use GuardsDamageActions;

    public const MINIMUM_REASON = 10;

    public function __construct(
        protected DamageValuation $valuation,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws DamageRefused
     * @throws AuthorizationException
     */
    public function handle(User $actor, OrderDamage $damage, ReturnParty $bearer, Money $loss, string $reason, ?string $writeOffReason = null): OrderDamage
    {
        $this->authorizeStaff($actor, PermissionAction::AssignLiability, 'decide who bears a damage');

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MINIMUM_REASON) {
            throw DamageRefused::decisionNeedsAReason();
        }

        $approved = $this->database->transaction(function () use ($actor, $damage, $bearer, $loss, $reason, $writeOffReason) {
            $locked = $this->locked($damage);

            // Already decided by the request that got here first.
            if ($locked->status->isDecided()) {
                return $locked;
            }

            if ($locked->status !== DamageStatus::LiabilityReview) {
                throw $locked->inspected_at === null ? DamageRefused::notInspected() : DamageRefused::notInThatState();
            }

            $this->checkLoss($locked, $loss, $writeOffReason);

            $locked->responsibilityChanges()->create([
                'previous_party' => null,
                'new_party' => $bearer,
                'changed_by' => $actor->id,
                'changed_at' => CarbonImmutable::now(),
                'reason' => $reason,
            ]);

            $locked->forceFill([
                'responsible_party' => $bearer,
                'approved_loss_amount' => $loss,
                'approval_reason' => $reason,
                'write_off_reason' => $loss->isZero() ? trim((string) $writeOffReason) : null,
                'liability_decided_by' => $actor->id,
                'liability_decided_at' => CarbonImmutable::now(),
            ]);

            $locked->moveTo(
                DamageStatus::Approved,
                new StatusChange(
                    actorId: $actor->id,
                    reason: $reason,
                    publicNote: 'damages.notes.approved.'.$bearer->value,
                ),
                OrderStatusChangeSource::Staff,
            );

            $locked->moveTo(
                DamageStatus::FinancialPostingPending,
                new StatusChange(actorId: $actor->id, reason: 'The loss is approved and waits to be posted.'),
                OrderStatusChangeSource::Staff,
            );

            return $locked;
        });

        $this->audit->handle(new AuditEntry(
            action: 'damage.liability_approved',
            actorId: $actor->id,
            auditableType: OrderDamage::class,
            auditableId: $approved->id,
            after: [
                'reference' => $approved->reference,
                'responsible_party' => $approved->responsible_party?->value,
                'approved_loss' => $approved->approved_loss_amount?->toDecimal(),
                'currency' => $approved->currency_code,
                'max_recoverable_value' => $approved->max_recoverable_value->toDecimal(),
            ],
            reason: $reason,
            accountId: $approved->business_account_id,
            module: PermissionModule::Damage->value,
        ));

        $damage->setRawAttributes($approved->getAttributes(), sync: true);

        return $approved;
    }

    /**
     * The most this case can cost: the line's frozen value of the units that
     * inspection found damaged — never more than the case was opened with.
     */
    public function maximumFor(OrderDamage $damage): Money
    {
        $damaged = $damage->dispositions()
            ->where('disposition', ReturnDisposition::Damaged->value)
            ->sum('quantity');

        $worth = $this->valuation->for($damage->orderItem, (int) $damaged)->maximum;

        return $worth->greaterThan($damage->max_recoverable_value) ? $damage->max_recoverable_value : $worth;
    }

    /**
     * @throws DamageRefused
     */
    protected function checkLoss(OrderDamage $damage, Money $loss, ?string $writeOffReason): void
    {
        if ($loss->currency->value !== $damage->currency_code) {
            throw DamageRefused::currencyMismatch();
        }

        if ($loss->isNegative()) {
            throw DamageRefused::lossIsNegative();
        }

        $maximum = $this->maximumFor($damage);

        if ($loss->greaterThan($maximum)) {
            throw DamageRefused::lossExceedsMaximum($maximum->toDecimal());
        }

        if ($loss->isZero() && mb_strlen(trim((string) $writeOffReason)) < self::MINIMUM_REASON) {
            throw DamageRefused::lossNeedsAWriteOffReason();
        }
    }
}
