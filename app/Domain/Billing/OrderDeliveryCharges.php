<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Data\DeliveryChargeResolution;
use App\Domain\Billing\Enums\DeliveryArea;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Exceptions\DeliveryChargeRefused;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Models\BdLocation;
use App\Support\Money\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The customer delivery charge (D37): one charge per order, Inside Dhaka or
 * Outside Dhaka, the same for every Partner.
 *
 *   1. The delivery address's structured location is walked up the BdLocation
 *      hierarchy to its **district**. No district (a division, nothing, an
 *      inactive or unknown place) cannot be priced: refused.
 *   2. Dhaka district -- natural key `source_id = 26` -- is Inside Dhaka; every
 *      other Bangladesh district is Outside Dhaka. A district of the Dhaka
 *      division other than Dhaka itself is Outside Dhaka.
 *   3. The area's global fee rule prices it. No rule: refused ("Delivery charge
 *      is not configured"), never charged as zero. A rule set to zero is free
 *      delivery on purpose.
 *
 * Never from address text, postcode, division, a client flag, quantity, weight
 * or courier price. Wholesale delivery is not this: it stays one flat charge.
 */
class OrderDeliveryCharges
{
    /** Dhaka district's natural key in the BdLocation directory (D37). */
    public const DHAKA_DISTRICT_SOURCE_ID = '26';

    /** What the readiness check and the admin screen say while a rate is missing. */
    public const NOT_CONFIGURED_MESSAGE = 'Delivery charge is not configured. Set both the Inside Dhaka and Outside Dhaka delivery charges before customers can check out.';

    /** How far up the hierarchy a location may sit below its district (union → upazila → district). */
    protected const MAX_DEPTH = 4;

    public function __construct(
        protected FeeRuleResolver $rules,
    ) {}

    /**
     * @throws DeliveryChargeRefused
     */
    public function forLocation(?BdLocation $location, Currency $currency, ?CarbonImmutable $at = null): DeliveryChargeResolution
    {
        $district = $this->districtOf($location) ?? throw DeliveryChargeRefused::addressUnresolved();
        $area = $this->areaOf($district);
        $rule = $this->rules->ruleFor($area->feeType(), null, $at ?? CarbonImmutable::now());

        if ($rule === null) {
            throw DeliveryChargeRefused::notConfigured();
        }

        if ($rule->amount->currency !== $currency) {
            // Never converted at a rate nobody agreed (D4): not configured for this currency.
            Log::warning('Customer delivery charge rule is priced in another currency.', [
                'fee_rule' => $rule->public_id,
                'rule_currency' => $rule->amount->currency->value,
                'currency' => $currency->value,
            ]);

            throw DeliveryChargeRefused::notConfigured();
        }

        return new DeliveryChargeResolution($rule->amount, $area, $district, $rule);
    }

    /**
     * The active district a location sits in, or null when it has none: a
     * division (above any district), an inactive or unknown place, or a broken
     * chain.
     */
    public function districtOf(?BdLocation $location): ?BdLocation
    {
        for ($depth = 0; $location !== null && $depth < self::MAX_DEPTH; $depth++) {
            if (! $location->is_active || $location->type === BdLocationType::Division) {
                return null;
            }

            if ($location->type === BdLocationType::District) {
                return $location;
            }

            $location = $location->parentLocation;
        }

        return null;
    }

    public function areaOf(BdLocation $district): DeliveryArea
    {
        return $district->source_id === self::DHAKA_DISTRICT_SOURCE_ID
            ? DeliveryArea::InsideDhaka
            : DeliveryArea::OutsideDhaka;
    }

    /**
     * The areas with no rate in force: customers cannot check out to them.
     * Empty means customer checkout is ready (the readiness check).
     *
     * @return list<DeliveryArea>
     */
    public function missingRates(?CarbonImmutable $at = null): array
    {
        $missing = [];

        foreach (DeliveryArea::cases() as $area) {
            if (! $this->rules->ruleFor($area->feeType(), null, $at) instanceof FeeRule) {
                $missing[] = $area;
            }
        }

        return $missing;
    }

    /**
     * The tax scope a customer delivery charge is taxed under, whichever area:
     * the delivery tax rules a deployment already has keep applying.
     */
    public static function taxScopeValue(): string
    {
        return FeeType::WebsiteDelivery->value;
    }
}
