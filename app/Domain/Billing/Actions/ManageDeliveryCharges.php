<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\DeliveryArea;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\FeeRuleResolver;
use App\Domain\Billing\Models\FeeRule;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * What Admin → Orders → Delivery Charges does, on the existing `fee_rules`:
 * the two customer rates (Inside and Outside Dhaka, D37) and the separate flat
 * wholesale charge. Global only -- the same for every Partner and plan.
 *
 * **A rate is never edited in place.** Setting a new amount closes the rule in
 * force and opens the next at the same instant ({@see ManageFeeRules}), so
 * every order keeps the charge it was placed with and each rule is a pricing
 * version an order can name. Every change is audited by ManageFeeRules.
 */
class ManageDeliveryCharges
{
    public function __construct(
        protected ManageFeeRules $feeRules,
        protected FeeRuleResolver $resolver,
        protected DatabaseManager $database,
    ) {}

    /**
     * The rates this screen manages, by the key the screen and its form use.
     *
     * @return array<string, FeeType>
     */
    public static function rates(): array
    {
        return [
            DeliveryArea::InsideDhaka->value => DeliveryArea::InsideDhaka->feeType(),
            DeliveryArea::OutsideDhaka->value => DeliveryArea::OutsideDhaka->feeType(),
            'wholesale' => FeeType::WholesaleDelivery,
        ];
    }

    /**
     * Set a rate from now. Zero is free delivery.
     *
     * @throws InvalidArgumentException
     */
    public function set(User $actor, FeeType $type, Money $amount, ?string $note = null): FeeRule
    {
        $this->assertManaged($type);

        return $this->database->transaction(function () use ($actor, $type, $amount, $note) {
            $now = CarbonImmutable::now();
            $current = $this->resolver->ruleFor($type, null, $now);

            if ($current !== null && $current->amount->currency === $amount->currency && $current->amount->equals($amount)) {
                return $current;
            }

            if ($current !== null) {
                $this->feeRules->close($actor, $current, $now);
            }

            return $this->feeRules->create(
                actor: $actor,
                type: $type,
                amount: $amount,
                effectiveFrom: $now,
                note: $note,
            );
        });
    }

    /**
     * Switch a rate off from now. A customer area with no rate cannot check
     * out until one is set again (fail closed, D37).
     *
     * @throws InvalidArgumentException
     */
    public function deactivate(User $actor, FeeRule $rule): void
    {
        $this->assertManaged($rule->fee_type);

        if ($rule->package_id !== null) {
            throw new InvalidArgumentException('Only the global delivery rates are managed here.');
        }

        $this->feeRules->close($actor, $rule);
    }

    protected function assertManaged(FeeType $type): void
    {
        if (! in_array($type, self::rates(), true)) {
            throw new InvalidArgumentException('Only delivery charges are managed here.');
        }
    }
}
