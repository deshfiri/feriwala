<?php

namespace App\Domain\Referral\Data;

use App\Domain\Referral\Enums\RewardType;
use InvalidArgumentException;

/**
 * One reward, as a plan version states it: fixed or percentage, and a cap (D24).
 *
 * The arithmetic lives here and nowhere else, in integers only:
 *
 *   - **fixed** pays its amount;
 *   - **percentage** pays `floor(base × basis points ÷ 10 000)` — always rounded
 *     **down** to the poisha, so the platform never pays a fraction of a poisha
 *     it did not have;
 *   - either is then held to its cap and to the base itself.
 *
 * A basis point is a hundredth of a percent, so 10% is 1 000 and 2.5% is 250.
 */
readonly class RewardRule
{
    public const BASIS_POINTS_IN_WHOLE = 10_000;

    public function __construct(
        public RewardType $type,
        public ?int $amountMinor = null,
        public ?int $rateBps = null,
        public ?int $capMinor = null,
    ) {
        $valid = match ($type) {
            RewardType::Fixed => $amountMinor !== null && $amountMinor > 0 && $rateBps === null,
            RewardType::Percentage => $rateBps !== null && $rateBps >= 1 && $rateBps <= self::BASIS_POINTS_IN_WHOLE && $amountMinor === null,
        };

        if (! $valid || ($capMinor !== null && $capMinor <= 0)) {
            throw new InvalidArgumentException('A reward is a positive fixed amount or a percentage between 1 and 10 000 basis points.');
        }
    }

    /**
     * What this rule pays on a base, before the chain's own limit.
     */
    public function amountFor(int $baseMinor): int
    {
        $base = max(0, $baseMinor);

        $amount = $this->type === RewardType::Fixed
            ? (int) $this->amountMinor
            : intdiv($base * (int) $this->rateBps, self::BASIS_POINTS_IN_WHOLE);

        if ($this->capMinor !== null) {
            $amount = min($amount, $this->capMinor);
        }

        return max(0, min($amount, $base));
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
     * The rule as it is kept on a commission: what was applied, not a pointer
     * to a row that could be misread later.
     *
     * @return array{type: string, amount_minor: int|null, rate_bps: int|null, cap_minor: int|null}
     */
    public function snapshot(): array
    {
        return [
            'type' => $this->type->value,
            'amount_minor' => $this->amountMinor,
            'rate_bps' => $this->rateBps,
            'cap_minor' => $this->capMinor,
        ];
    }
}
