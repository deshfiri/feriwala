<?php

namespace App\Domain\Referral\Actions;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Referral\CommissionBaseCalculator;
use App\Domain\Referral\Data\RewardRule;
use App\Domain\Referral\Enums\CommissionSkipReason;
use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Enums\ReferralTrigger;
use App\Domain\Referral\Jobs\ReleaseReferralCommissions;
use App\Domain\Referral\Models\AccountReferral;
use App\Domain\Referral\Models\ReferralCommission;
use App\Domain\Referral\Models\ReferralPlan;
use App\Domain\Referral\Models\ReferralPlanLevel;
use App\Domain\Referral\Models\ReferralQualifyingEvent;
use App\Domain\Referral\Queries\ReferralHierarchy;
use App\Domain\Referral\Queries\ResolveReferralPlan;
use App\Domain\Referral\ReferralSettings;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The multi-level commission engine (D24, P7-42).
 *
 * Called **inside the qualifying event's own transaction** — today the
 * account's activation — and writes only rows: the event, and one commission
 * per level it decided. Nothing reaches a wallet here; the commissions are the
 * outbox that {@see ReleaseReferralCommission} pays from once the transaction
 * has committed and each holding period has passed.
 *
 * The rules, in order:
 *
 *   1. Nothing at all while the global switch is off or no plan version is in
 *      force for the account's package or globally.
 *   2. **One event per account activation, ever.** A second call — a retry, a
 *      duplicate callback, a second worker — finds it and returns it.
 *   3. The base comes from the activation payment's allocations. A payment
 *      below the version's minimum qualifies for nothing.
 *   4. Ancestors are read level 1 up to the version's depth. **Each level is
 *      decided on its own**: a missing ancestor ends the chain; an ancestor who
 *      is not paid — level switched off, account state, package, too few
 *      active direct referrals — is recorded as skipped at *their* level, and
 *      the one above keeps *theirs*.
 *   5. Each amount is the level's rule on the base, then held to what is left
 *      of the base: levels from 1 upward, then the joining reward. A level
 *      that would pass the base is reduced to what remains and marked capped.
 *   6. The links the chain was read through are locked: those referrers can
 *      no longer change.
 */
class CalculateReferralCommissions
{
    public function __construct(
        protected DatabaseManager $database,
        protected ReferralSettings $settings,
        protected ResolveReferralPlan $plans,
        protected ReferralHierarchy $hierarchy,
        protected CommissionBaseCalculator $bases,
        protected RecordAuditLog $audit,
    ) {}

    public function forActivation(BusinessAccount $account, ?CarbonImmutable $at = null): ?ReferralQualifyingEvent
    {
        $at ??= CarbonImmutable::now();

        if (! $this->settings->enabled()) {
            return null;
        }

        $existing = $this->existing(ReferralTrigger::AccountActivation, $account->id);

        if ($existing !== null) {
            return $existing;
        }

        /** @var UserPackage|null $subscription */
        $subscription = $account->current_user_package_id === null
            ? null
            : UserPackage::query()->find($account->current_user_package_id);

        $plan = $this->plans->for(ReferralTrigger::AccountActivation, $subscription?->package_id, $at);

        if ($plan === null) {
            return null;
        }

        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('business_account_id', $account->id)
            ->where('purpose', PaymentPurpose::Activation->value)
            ->where('status', PaymentStatus::Paid->value)
            ->latest('completed_at')
            ->latest('id')
            ->first();

        if ($payment === null || $payment->amount->lessThan($plan->minimum_qualifying_payment)) {
            return null;
        }

        $base = $this->bases->for($payment, $plan->commission_base);
        $ancestors = $this->hierarchy->ancestors($account->id, $plan->max_depth);

        $decision = $this->decide($plan, $account, $ancestors, $base, $at);

        return $this->database->transaction(function () use ($account, $plan, $payment, $base, $ancestors, $at, $decision) {
            try {
                // A savepoint of its own: losing the race for the unique index
                // must not abort the activation around it.
                $event = $this->database->transaction(fn () => ReferralQualifyingEvent::create([
                    'trigger_event' => ReferralTrigger::AccountActivation,
                    'source_account_id' => $account->id,
                    'subject_type' => ReferralQualifyingEvent::SUBJECT_ACCOUNT,
                    'subject_id' => $account->id,
                    'payment_id' => $payment->id,
                    'referral_plan_id' => $plan->id,
                    'currency_code' => $plan->currency_code,
                    'commission_base' => $base,
                    'chain' => $decision['chain'],
                    'status' => ReferralQualifyingEvent::RECORDED,
                    'occurred_at' => $at,
                ]));
            } catch (UniqueConstraintViolationException) {
                return $this->existing(ReferralTrigger::AccountActivation, $account->id);
            }

            foreach ($decision['rows'] as $row) {
                ReferralCommission::create([
                    'referral_qualifying_event_id' => $event->id,
                    'referral_plan_id' => $plan->id,
                    'source_account_id' => $account->id,
                    'currency_code' => $plan->currency_code,
                    'commission_base' => $base,
                    'available_at' => $at->addDays($plan->holding_days),
                    ...$row,
                ]);
            }

            // The links this chain was read through are part of the record now.
            AccountReferral::query()
                ->whereIn('referred_account_id', [$account->id, ...array_slice(array_values($ancestors), 0, -1)])
                ->whereNull('locked_at')
                ->update(['locked_at' => $at]);

            $this->audit->handle(new AuditEntry(
                action: 'referral.commissions_calculated',
                auditableType: ReferralQualifyingEvent::class,
                auditableId: $event->id,
                after: [
                    'event' => $event->public_id,
                    'plan' => $plan->public_id,
                    'commission_base' => $base->jsonSerialize(),
                    'levels' => array_map(fn (array $level) => [$level['level'], $level['outcome']], $decision['chain']),
                ],
                accountId: $account->id,
                module: 'referral',
            ));

            $eventId = $event->id;
            $this->database->afterCommit(fn () => ReleaseReferralCommissions::dispatch($eventId));

            return $event;
        });
    }

    /**
     * Every level, decided before anything is written.
     *
     * @param  array<int, int>  $ancestors  level => account id
     * @return array{rows: list<array<string, mixed>>, chain: list<array{level: int, account: string|null, outcome: string}>}
     */
    protected function decide(ReferralPlan $plan, BusinessAccount $source, array $ancestors, Money $base, CarbonImmutable $at): array
    {
        $remaining = $base;
        $rows = [];
        $chain = [];

        $accounts = BusinessAccount::query()
            ->whereIn('id', array_values($ancestors))
            ->get()
            ->keyBy('id');

        foreach ($plan->levels as $level) {
            $beneficiaryId = $ancestors[$level->level] ?? null;

            if ($beneficiaryId === null) {
                // The chain ends here: nobody to pay, at this level or above.
                $chain[] = ['level' => $level->level, 'account' => null, 'outcome' => 'missing'];

                continue;
            }

            /** @var BusinessAccount $beneficiary */
            $beneficiary = $accounts[$beneficiaryId];

            $skip = $this->skipFor($plan, $level, $beneficiary);
            [$amount, $capped] = $skip === null ? $this->within($level->rule(), $base, $remaining) : [Money::zero($base->currency), false];

            if ($skip === null && $amount->isZero()) {
                $skip = $capped ? CommissionSkipReason::BaseExhausted : CommissionSkipReason::NothingToPay;
            }

            $remaining = $remaining->minus($amount);

            $rows[] = $this->row($beneficiary->id, $level->level, ReferralCommission::KIND_LEVEL, [
                ...$level->rule()->snapshot(),
                'enabled' => $level->is_enabled,
                'required_package_ids' => $level->requiredPackageIds(),
                'min_active_direct_referrals' => $level->min_active_direct_referrals,
            ], $amount, $capped, $skip);

            $chain[] = [
                'level' => $level->level,
                'account' => $beneficiary->public_id,
                'outcome' => $skip === null ? 'pending' : $skip->value,
            ];
        }

        // The new account's joining reward, only when it was referred (D14).
        $joining = $plan->joiningRule();

        if ($joining !== null && isset($ancestors[1])) {
            [$amount, $capped] = $this->within($joining, $base, $remaining);
            $skip = $amount->isZero() ? ($capped ? CommissionSkipReason::BaseExhausted : CommissionSkipReason::NothingToPay) : null;

            $rows[] = $this->row($source->id, 0, ReferralCommission::KIND_JOINING, $joining->snapshot(), $amount, $capped, $skip);

            $chain[] = ['level' => 0, 'account' => $source->public_id, 'outcome' => $skip === null ? 'pending' : $skip->value];
        }

        return ['rows' => $rows, 'chain' => $chain];
    }

    /**
     * Why an ancestor is not paid at this level, or null when they are.
     */
    protected function skipFor(ReferralPlan $plan, ReferralPlanLevel $level, BusinessAccount $beneficiary): ?CommissionSkipReason
    {
        if (! $level->is_enabled) {
            return CommissionSkipReason::LevelDisabled;
        }

        if (! $plan->qualifiesStatus($beneficiary->status)) {
            return CommissionSkipReason::StatusNotQualified;
        }

        $required = $level->requiredPackageIds();

        if ($required !== []) {
            $packageId = $beneficiary->current_user_package_id === null
                ? null
                : UserPackage::query()->whereKey($beneficiary->current_user_package_id)->value('package_id');

            if (! in_array((int) $packageId, $required, true)) {
                return CommissionSkipReason::PackageNotEligible;
            }
        }

        if ($level->min_active_direct_referrals > 0) {
            $active = AccountReferral::query()
                ->where('referrer_account_id', $beneficiary->id)
                ->whereHas('referred', fn ($account) => $account->where('status', AccountStatus::Active->value))
                ->count();

            if ($active < $level->min_active_direct_referrals) {
                return CommissionSkipReason::TooFewDirectReferrals;
            }
        }

        return null;
    }

    /**
     * The rule's amount, held to what is left of the base.
     *
     * @return array{0: Money, 1: bool} amount, and whether the base cut it
     */
    protected function within(RewardRule $rule, Money $base, Money $remaining): array
    {
        $amount = $rule->amountFor($base);

        if ($amount->greaterThan($remaining)) {
            $clamped = $remaining->isNegative() ? Money::zero($remaining->currency) : $remaining;

            return [$clamped, true];
        }

        return [$amount, false];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    protected function row(int $beneficiaryId, int $level, string $kind, array $snapshot, Money $amount, bool $capped, ?CommissionSkipReason $skip): array
    {
        return [
            'beneficiary_account_id' => $beneficiaryId,
            'level' => $level,
            'kind' => $kind,
            'rule_snapshot' => $snapshot,
            'amount' => $skip === null ? $amount : Money::zero($amount->currency),
            'capped' => $capped,
            'status' => $skip === null ? CommissionStatus::Pending : CommissionStatus::Skipped,
            'skip_reason' => $skip,
        ];
    }

    protected function existing(ReferralTrigger $trigger, int $accountId): ?ReferralQualifyingEvent
    {
        /** @var ReferralQualifyingEvent|null $event */
        $event = ReferralQualifyingEvent::query()
            ->where('trigger_event', $trigger->value)
            ->where('subject_type', ReferralQualifyingEvent::SUBJECT_ACCOUNT)
            ->where('subject_id', $accountId)
            ->first();

        return $event;
    }
}
