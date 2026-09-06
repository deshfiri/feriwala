<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Data\RefundEligibility;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\Refundability;
use App\Domain\Billing\Models\Payment;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Money;

/**
 * What may be refunded, and on what condition (D17).
 *
 * The single place the question is answered. A refund screen, a closure
 * workflow and a gateway chargeback all need it, and three implementations is
 * how a registration fee comes to be refundable down one path and not another.
 *
 * Every rule is admin-configurable — D17 requires that — but the **defaults are
 * the restrictive reading**: a component nobody has configured is
 * non-refundable. A misconfiguration then refuses a refund a human can grant,
 * rather than paying out money nobody agreed to.
 *
 * This decides eligibility only. Nothing here refunds anything: D17 makes every
 * refund an administrative decision, so the answer is always "may be refunded,
 * with approval" rather than "refunded".
 */
class RefundabilityPolicy
{
    /** Where an administrator configures a component's rule. */
    public const SETTING_PREFIX = 'billing.refundability.';

    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    /**
     * The configured rule for a component, falling back to the D17 default.
     */
    public function ruleFor(AllocationType $type): Refundability
    {
        $configured = $this->settings->get(self::SETTING_PREFIX.$type->value);

        if (! is_string($configured)) {
            return Refundability::defaultFor($type);
        }

        // An unrecognised stored value falls back rather than throwing. A typo
        // in a settings row should not take down a refund screen, and the
        // fallback is the safe direction.
        return Refundability::tryFrom($configured) ?? Refundability::defaultFor($type);
    }

    /**
     * Whether one component of a settled payment may be given back.
     */
    public function evaluate(Payment $payment, AllocationType $type): RefundEligibility
    {
        $amount = $payment->allocatedTo($type);
        $rule = $this->ruleFor($type);

        if (! $payment->status->isSettled()) {
            // Nothing has arrived, so there is nothing to give back. Cancelling
            // an unsettled payment is a different action with different
            // consequences, and conflating them is how a refund gets issued
            // against money that never came in.
            return RefundEligibility::refusedBy(
                $type,
                $rule,
                $amount,
                __('This payment has not been settled, so there is nothing to refund.'),
            );
        }

        if ($amount->isZero()) {
            return RefundEligibility::refusedBy(
                $type,
                $rule,
                $amount,
                __('This payment carries no :component.', ['component' => mb_strtolower($type->label())]),
            );
        }

        return match ($rule) {
            Refundability::Never => RefundEligibility::refusedBy(
                $type,
                $rule,
                $amount,
                __('The :component is not refundable.', ['component' => mb_strtolower($type->label())]),
            ),

            Refundability::BeforeActivation => $this->beforeActivation($payment, $type, $rule, $amount),

            Refundability::Always => RefundEligibility::allowed($type, $rule, $amount),
        };
    }

    /**
     * Every component of a payment, refundable or not.
     *
     * The refused ones are included on purpose. An administrator looking at a
     * refund needs to see that the registration fee exists and is not coming
     * back, not have it quietly absent from the list.
     *
     * @return array<int, RefundEligibility>
     */
    public function evaluateAll(Payment $payment): array
    {
        $types = [];

        foreach ($payment->allocations as $allocation) {
            $types[$allocation->type->value] = $allocation->type;
        }

        return array_values(array_map(
            fn (AllocationType $type) => $this->evaluate($payment, $type),
            $types,
        ));
    }

    /**
     * The total that could be given back, across every component.
     */
    public function refundableTotal(Payment $payment): Money
    {
        $total = Money::zero($payment->amount_minor->currency);

        foreach ($this->evaluateAll($payment) as $eligibility) {
            $total = $total->plus($eligibility->refundableAmount);
        }

        return $total;
    }

    /**
     * Refundable only while the business has not started trading.
     *
     * Once activated, the account has had what it paid for; unwinding that is a
     * closure question with its own retention rules (D18), not a refund.
     */
    protected function beforeActivation(
        Payment $payment,
        AllocationType $type,
        Refundability $rule,
        Money $amount,
    ): RefundEligibility {
        $account = $payment->businessAccount;

        if ($account !== null && $account->isActivated()) {
            return RefundEligibility::refusedBy(
                $type,
                $rule,
                $amount,
                __('This account was activated on :date, and the :component is not refundable after activation.', [
                    'date' => $account->activated_at?->toFormattedDayDateString() ?? '',
                    'component' => mb_strtolower($type->label()),
                ]),
            );
        }

        return RefundEligibility::allowed($type, $rule, $amount);
    }
}
