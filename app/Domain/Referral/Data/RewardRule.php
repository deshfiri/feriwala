<?php

namespace App\Domain\Referral\Data;

use App\Domain\Referral\Enums\RewardType;
use App\Support\Money\Money;
use InvalidArgumentException;
use RoundingMode;

/**
 * One reward, as a plan version states it: fixed or percentage, and a cap (D24).
 *
 * The arithmetic lives here and nowhere else, in exact flat-Taka {@see Money} (D26):
 *
 *   - **fixed** pays its amount;
 *   - **percentage** pays `base × basis points ÷ 10 000`, always rounded **down**
 *     (`RoundingMode::TowardsZero`) to the currency's smallest unit, so the platform
 *     never pays a fraction of a poisha it did not have;
 *   - either is then held to its cap and to the base itself.
 *
 * A basis point is a hundredth of a percent, so 10% is 1 000 and 2.5% is 250.
 */
readonly class RewardRule
{
    public const BASIS_POINTS_IN_WHOLE = 10_000;

    public function __construct(
        public RewardType $type,
        public ?Money $amount = null,
        public ?int $rateBps = null,
        public ?Money $cap = null,
    ) {
        $valid = match ($type) {
            RewardType::Fixed => $amount !== null && $amount->isPositive() && $rateBps === null,
            RewardType::Percentage => $rateBps !== null && $rateBps >= 1 && $rateBps <= self::BASIS_POINTS_IN_WHOLE && $amount === null,
        };

        if (! $valid || ($cap !== null && ! $cap->isPositive())) {
            throw new InvalidArgumentException('A reward is a positive fixed amount or a percentage between 1 and 10 000 basis points.');
        }
    }

    /**
     * What this rule pays on a base, before the chain's own limit.
     */
    public function amountFor(Money $base): Money
    {
        $base = $base->isNegative() ? Money::zero($base->currency) : $base;

        $amount = $this->type === RewardType::Fixed
            ? ($this->amount ?? throw new InvalidArgumentException('A fixed reward always carries an amount; the constructor guarantees it.'))
            : $base->percentage(self::percentFromBps((int) $this->rateBps), RoundingMode::TowardsZero);

        if ($this->cap !== null && $amount->greaterThan($this->cap)) {
            $amount = $this->cap;
        }

        return $amount->greaterThan($base) ? $base : $amount;
    }

    /**
     * Basis points from a percentage typed as text, without a float anywhere:
     * `"10"` → 1 000, `"2.5"` → 250, `"0.01"` → 1. Null for anything else.
     */
    public static function basisPointsFromPercent(string $percent): ?int
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', trim($percent), $parts) !== 1) {
            return null;
        }

        $basisPoints = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');

        return $basisPoints >= 1 && $basisPoints <= self::BASIS_POINTS_IN_WHOLE ? $basisPoints : null;
    }

    /**
     * A basis-point count as a percent decimal string, e.g. `250` → `"2.50"`,
     * without a float: bcmath's `percentage()` divides by 100 itself, so this
     * only needs to place the decimal point two digits from bps' own scale.
     */
    private static function percentFromBps(int $bps): string
    {
        return sprintf('%d.%02d', intdiv($bps, 100), $bps % 100);
    }

    /**
     * The rule as it is kept on a commission: what was applied, not a pointer
     * to a row that could be misread later.
     *
     * @return array{type: string, amount: array<string, mixed>|null, rate_bps: int|null, cap: array<string, mixed>|null}
     */
    public function snapshot(): array
    {
        return [
            'type' => $this->type->value,
            'amount' => $this->amount?->jsonSerialize(),
            'rate_bps' => $this->rateBps,
            'cap' => $this->cap?->jsonSerialize(),
        ];
    }
}
