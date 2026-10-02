<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Data\DeliveryChargeCalculation;
use App\Domain\Billing\Models\DeliveryChargeRule;
use App\Domain\Catalog\Data\ProductLogistics;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * What a shipment's delivery costs, from its lines' own logistics figures
 * (beta-critical batch, Commit 2).
 *
 * The same outward-walk-by-specificity idiom {@see FeeRuleResolver} already
 * uses for `fee_rules`: the most specific active {@see DeliveryChargeRule}
 * covering the chargeable weight wins -- courier+area, then area, then
 * courier, then the fully general rule -- and where none covers it the base
 * charge is zero, never a guess (the same "unconfigured means zero" rule
 * {@see FeeRuleResolver::wholesaleDelivery()} already follows).
 *
 * Every figure here is exact: weight arithmetic is plain integer grams
 * (PHP's own exact integer type, never a float), and every monetary and
 * volumetric calculation runs through {@see Money} or bcmath directly (D26
 * extended to physical measurements) -- nothing here ever calls `round()`,
 * casts to `float`, or multiplies/divides a figure through an intermediate
 * floating-point representation.
 */
class CalculateDeliveryCharge
{
    /** Decimal places kept for an intermediate cm³ volume before it is turned into whole grams. */
    private const VOLUME_SCALE = 6;

    public function __construct(
        protected DeliveryChargeSettings $settings,
    ) {}

    /**
     * @param  list<array{logistics: ProductLogistics, quantity: int}>  $lines
     */
    public function handle(
        array $lines,
        Currency $currency,
        ?Money $orderSubtotal = null,
        ?string $area = null,
        ?int $courierProviderId = null,
        ?CarbonImmutable $at = null,
    ): DeliveryChargeCalculation {
        $at ??= CarbonImmutable::now();

        $pieces = 0;
        $boxes = 0;
        $actualWeightGrams = 0;
        $volumetricCmCubed = '0';
        $anyFragile = false;

        foreach ($lines as $line) {
            $logistics = $line['logistics'];
            $quantity = max(0, $line['quantity']);

            if ($quantity === 0) {
                continue;
            }

            $pieces += $quantity;
            $anyFragile = $anyFragile || $logistics->isFragile;

            if ($logistics->shipsByBox && $logistics->piecesPerBox !== null && $logistics->piecesPerBox > 0) {
                // A partial final box still counts as one whole box -- plain
                // integer division, exact by construction.
                $lineBoxes = intdiv($quantity, $logistics->piecesPerBox)
                    + ($quantity % $logistics->piecesPerBox > 0 ? 1 : 0);
                $boxes += $lineBoxes;

                $actualWeightGrams += $logistics->boxWeightGrams !== null
                    ? $lineBoxes * $logistics->boxWeightGrams
                    : $quantity * ($logistics->unitWeightGrams() ?? 0);

                $volumetricCmCubed = bcadd(
                    $volumetricCmCubed,
                    $this->boxVolumeCmCubed($logistics, $lineBoxes),
                    self::VOLUME_SCALE,
                );

                continue;
            }

            $actualWeightGrams += $quantity * ($logistics->unitWeightGrams() ?? 0);
            $volumetricCmCubed = bcadd(
                $volumetricCmCubed,
                $this->unitVolumeCmCubed($logistics, $quantity),
                self::VOLUME_SCALE,
            );
        }

        // The standard courier convention (cm3 / divisor, divisor typically
        // 5000 or 6000) yields *kilograms*, not grams -- the x1000 here is
        // the unit conversion into this calculator's own canonical grams,
        // not a second divisor.
        $divisor = $this->settings->volumetricDivisor();
        $volumetricWeightGrams = $this->ceilDivide(bcmul($volumetricCmCubed, '1000', self::VOLUME_SCALE), $divisor);

        $useGreater = $this->settings->useGreaterOfActualAndVolumetric();
        $usedVolumetric = $useGreater && $volumetricWeightGrams > $actualWeightGrams;
        $chargeableWeightGrams = $usedVolumetric ? $volumetricWeightGrams : $actualWeightGrams;

        $rule = $this->resolveRule($chargeableWeightGrams, $area, $courierProviderId, $at);

        $baseCharge = $this->baseChargeFor($rule, $currency);
        $perKgRate = $this->perKgRateFor($rule, $currency);
        $chargeableKg = bcdiv((string) $chargeableWeightGrams, '1000', self::VOLUME_SCALE);
        $weightCharge = $perKgRate->multipliedBy($chargeableKg);

        $boxCharge = $boxes > 0 ? $this->settings->perBoxCharge($currency)->multipliedBy($boxes) : Money::zero($currency);
        $handlingCharge = $anyFragile ? $this->settings->fragileHandlingCharge($currency) : Money::zero($currency);

        $subtotal = $baseCharge->plus($weightCharge)->plus($boxCharge)->plus($handlingCharge);

        $minimum = $this->settings->minimumCharge($currency);
        $maximum = $this->settings->maximumCharge($currency);

        $bounded = $subtotal->lessThan($minimum) ? $minimum : $subtotal;
        $bounded = $maximum !== null && $bounded->greaterThan($maximum) ? $maximum : $bounded;

        $threshold = $this->settings->freeDeliveryThreshold($currency);
        $freeDeliveryApplied = $threshold !== null
            && $orderSubtotal !== null
            && $orderSubtotal->greaterThanOrEqualTo($threshold);

        $finalCharge = $freeDeliveryApplied ? Money::zero($currency) : $bounded;

        return new DeliveryChargeCalculation(
            pieces: $pieces,
            boxes: $boxes,
            actualWeightGrams: $actualWeightGrams,
            volumetricWeightGrams: $volumetricWeightGrams,
            chargeableWeightGrams: $chargeableWeightGrams,
            usedVolumetricWeight: $usedVolumetric,
            rule: $rule,
            baseCharge: $baseCharge,
            weightCharge: $weightCharge,
            boxCharge: $boxCharge,
            handlingCharge: $handlingCharge,
            subtotalBeforeBounds: $subtotal,
            freeDeliveryApplied: $freeDeliveryApplied,
            finalCharge: $finalCharge,
        );
    }

    /**
     * The most specific active rule covering this weight at this moment --
     * courier+area, then area, then courier, then the fully general rule.
     * Ties within the same level of specificity go to the higher `priority`,
     * then the most recently made effective.
     */
    protected function resolveRule(
        int $chargeableWeightGrams,
        ?string $area,
        ?int $courierProviderId,
        CarbonImmutable $at,
    ): ?DeliveryChargeRule {
        $candidates = DeliveryChargeRule::query()
            ->effectiveAt($at)
            ->where('weight_from_grams', '<=', $chargeableWeightGrams)
            ->where(fn ($query) => $query
                ->whereNull('weight_to_grams')
                ->orWhere('weight_to_grams', '>', $chargeableWeightGrams))
            ->where(fn ($query) => $query
                ->whereNull('area')
                ->when($area !== null, fn ($inner) => $inner->orWhere('area', $area)))
            ->where(fn ($query) => $query
                ->whereNull('courier_provider_id')
                ->when($courierProviderId !== null, fn ($inner) => $inner->orWhere('courier_provider_id', $courierProviderId)))
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sort(function (DeliveryChargeRule $a, DeliveryChargeRule $b) use ($area, $courierProviderId) {
                $specificity = $this->specificity($b, $area, $courierProviderId) <=> $this->specificity($a, $area, $courierProviderId);

                if ($specificity !== 0) {
                    return $specificity;
                }

                $priority = $b->priority <=> $a->priority;

                if ($priority !== 0) {
                    return $priority;
                }

                return $b->effective_from->getTimestamp() <=> $a->effective_from->getTimestamp();
            })
            ->first();
    }

    /**
     * 2 for a rule naming both the area and the courier, 1 for naming
     * either, 0 for the fully general rule -- specificity always outranks
     * priority, the same rule {@see FeeRuleResolver} holds for `fee_rules`.
     */
    protected function specificity(DeliveryChargeRule $rule, ?string $area, ?int $courierProviderId): int
    {
        $matchesArea = $rule->area !== null && $rule->area === $area;
        $matchesCourier = $rule->courier_provider_id !== null && $rule->courier_provider_id === $courierProviderId;

        return (int) $matchesArea + (int) $matchesCourier;
    }

    protected function baseChargeFor(?DeliveryChargeRule $rule, Currency $currency): Money
    {
        if ($rule === null) {
            return Money::zero($currency);
        }

        return $rule->base_charge;
    }

    protected function perKgRateFor(?DeliveryChargeRule $rule, Currency $currency): Money
    {
        if ($rule === null || $rule->per_kg_charge === null) {
            return $this->settings->additionalPerKgCharge($currency);
        }

        return $rule->per_kg_charge;
    }

    /**
     * @return numeric-string
     */
    protected function unitVolumeCmCubed(ProductLogistics $logistics, int $quantity): string
    {
        if ($logistics->lengthCm === null || $logistics->widthCm === null || $logistics->heightCm === null) {
            return '0';
        }

        $length = $logistics->lengthCm;
        $width = $logistics->widthCm;
        $height = $logistics->heightCm;
        assert(is_numeric($length) && is_numeric($width) && is_numeric($height));

        $perUnit = bcmul(bcmul($length, $width, self::VOLUME_SCALE), $height, self::VOLUME_SCALE);

        return bcmul($perUnit, (string) $quantity, self::VOLUME_SCALE);
    }

    /**
     * @return numeric-string
     */
    protected function boxVolumeCmCubed(ProductLogistics $logistics, int $boxes): string
    {
        if ($logistics->boxLengthCm === null || $logistics->boxWidthCm === null || $logistics->boxHeightCm === null) {
            return '0';
        }

        $length = $logistics->boxLengthCm;
        $width = $logistics->boxWidthCm;
        $height = $logistics->boxHeightCm;
        assert(is_numeric($length) && is_numeric($width) && is_numeric($height));

        $perBox = bcmul(bcmul($length, $width, self::VOLUME_SCALE), $height, self::VOLUME_SCALE);

        return bcmul($perBox, (string) $boxes, self::VOLUME_SCALE);
    }

    /**
     * Ceiling division of an exact decimal numerator by a positive integer
     * divisor -- never a float, never `ceil()` on a cast. A nonzero
     * remainder always rounds the chargeable weight up, the same way a
     * courier's own volumetric-weight convention does.
     *
     * @param  numeric-string  $numerator
     */
    protected function ceilDivide(string $numerator, int $divisor): int
    {
        $floor = bcdiv($numerator, (string) $divisor, 0);
        $remainder = bcsub($numerator, bcmul($floor, (string) $divisor, self::VOLUME_SCALE), self::VOLUME_SCALE);

        return bccomp($remainder, '0', self::VOLUME_SCALE) > 0 ? ((int) $floor) + 1 : (int) $floor;
    }
}
