<?php

use App\Domain\Billing\Enums\DeliveryArea;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Exceptions\DeliveryChargeRefused;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Billing\OrderDeliveryCharges;
use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Models\BdLocation;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/*
 * The customer delivery charge (D37): Inside Dhaka for Dhaka district (natural
 * key 26), Outside Dhaka for every other district -- from the canonical
 * location only -- and refused, never zero, when it cannot be priced.
 */

beforeEach(function () {
    $this->geo = deliveryTestDhakaGeography();
    $this->charges = app(OrderDeliveryCharges::class);

    deliveryTestRate(FeeType::CustomerDeliveryInsideDhaka, '80.00');
    deliveryTestRate(FeeType::WebsiteDelivery, '130.00');
});

/** The refusal reason pricing `$location` throws, or null when it prices. */
function customerDeliveryRefusal(?BdLocation $location): ?string
{
    try {
        app(OrderDeliveryCharges::class)->forLocation($location, Currency::BDT);
    } catch (DeliveryChargeRefused $refused) {
        return $refused->reason;
    }

    return null;
}

it('charges Inside Dhaka for Dhaka district and anything inside it', function () {
    $union = BdLocation::create([
        'type' => BdLocationType::Union, 'parent_id' => $this->geo['dhaka_north']->id,
        'source_id' => '9001', 'source_parent_id' => '493', 'name_en' => 'A ward', 'name_bn' => 'A ward', 'is_active' => true,
    ]);

    foreach ([$this->geo['dhaka'], $this->geo['dhaka_north'], $union] as $place) {
        $charge = $this->charges->forLocation($place, Currency::BDT);

        expect($charge->area)->toBe(DeliveryArea::InsideDhaka)
            ->and($charge->district->is($this->geo['dhaka']))->toBeTrue()
            ->and($charge->amount->toDecimal())->toBe('80.00')
            ->and($charge->rule->fee_type)->toBe(FeeType::CustomerDeliveryInsideDhaka);
    }
});

it('charges Outside Dhaka for another district of Dhaka division, and for any other district', function () {
    foreach ([$this->geo['gazipur'], $this->geo['chattogram']] as $district) {
        $charge = $this->charges->forLocation($district, Currency::BDT);

        expect($charge->area)->toBe(DeliveryArea::OutsideDhaka)
            ->and($charge->amount->toDecimal())->toBe('130.00');
    }
});

it('never decides by name: a district called Dhaka that is not district 26 is Outside Dhaka', function () {
    $impostor = BdLocation::create([
        'type' => BdLocationType::District, 'parent_id' => $this->geo['chattogram_division']->id,
        'source_id' => '777', 'source_parent_id' => '20', 'name_en' => 'Dhaka', 'name_bn' => 'ঢাকা', 'is_active' => true,
    ]);

    expect($this->charges->forLocation($impostor, Currency::BDT)->area)->toBe(DeliveryArea::OutsideDhaka);
});

it('refuses an address it cannot place in a district', function () {
    $inactive = BdLocation::create([
        'type' => BdLocationType::District, 'parent_id' => $this->geo['dhaka_division']->id,
        'source_id' => '888', 'source_parent_id' => '30', 'name_en' => 'Closed', 'name_bn' => 'Closed', 'is_active' => false,
    ]);

    expect(customerDeliveryRefusal(null))->toBe(DeliveryChargeRefused::ADDRESS_UNRESOLVED)
        ->and(customerDeliveryRefusal($this->geo['dhaka_division']))->toBe(DeliveryChargeRefused::ADDRESS_UNRESOLVED)
        ->and(customerDeliveryRefusal($inactive))->toBe(DeliveryChargeRefused::ADDRESS_UNRESOLVED);
});

it('fails closed when the area has no rate, or a rate in another currency', function () {
    FeeRule::query()->where('fee_type', FeeType::CustomerDeliveryInsideDhaka->value)->update(['is_active' => false]);

    expect(customerDeliveryRefusal($this->geo['dhaka']))->toBe(DeliveryChargeRefused::NOT_CONFIGURED)
        // The other area is unaffected.
        ->and(customerDeliveryRefusal($this->geo['gazipur']))->toBeNull()
        ->and($this->charges->missingRates())->toBe([DeliveryArea::InsideDhaka]);

    FeeRule::query()->where('fee_type', FeeType::WebsiteDelivery->value)->update(['currency_code' => 'USD']);

    expect(customerDeliveryRefusal($this->geo['gazipur']))->toBe(DeliveryChargeRefused::NOT_CONFIGURED);
});

it('takes a rate of zero as free delivery, on purpose', function () {
    FeeRule::query()->where('fee_type', FeeType::CustomerDeliveryInsideDhaka->value)->update(['is_active' => false]);
    FeeRule::create([
        'fee_type' => FeeType::CustomerDeliveryInsideDhaka->value, 'currency_code' => 'BDT',
        'amount' => Money::fromDecimal('0.00'), 'effective_from' => now()->subHour(),
    ]);

    $charge = $this->charges->forLocation($this->geo['dhaka'], Currency::BDT);

    expect($charge->isFree())->toBeTrue()
        ->and($charge->amount->toDecimal())->toBe('0.00');
});
