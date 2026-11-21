<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Billing\OrderDeliveryCharges;
use App\Domain\Order\Models\Order;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Admin → Orders → Delivery Charges: the Inside Dhaka and Outside Dhaka customer
 * rates (D37) and the separate wholesale charge, on the existing fee rules,
 * changed only by closing a rule and opening the next.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
});

/** The global rule in force now for a fee type. */
function deliveryChargeScreenRule(FeeType $type): ?FeeRule
{
    return FeeRule::query()->where('fee_type', $type->value)
        ->whereNull('package_id')
        ->effectiveAt(now()->toImmutable())
        ->first();
}

it('shows the two customer rates and the wholesale rate, a server-worked preview, and whether checkout is ready', function () {
    deliveryTestRate(FeeType::CustomerDeliveryInsideDhaka, '80.00');

    $this->actingAs($this->admin)
        ->get(route('admin.delivery-charges.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/delivery-charges/index')
            ->where('rates.0.key', 'inside_dhaka')
            ->where('rates.0.group', 'customer')
            ->where('rates.0.rule.amount.amount', '80.00')
            ->where('rates.0.preview.subtotal.amount', '1000.00')
            ->where('rates.0.preview.total.amount', '1080.00')
            ->where('rates.1.key', 'outside_dhaka')
            ->where('rates.1.rule', null)
            ->where('rates.2.key', 'wholesale')
            ->where('rates.2.group', 'wholesale')
            // Outside Dhaka is missing, so customers cannot check out.
            ->where('customer_checkout_ready', false)
            ->where('can_manage', true));
});

it('sets a rate and replaces it at the same instant, never editing a rule', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.delivery-charges.store'), ['rate' => 'inside_dhaka', 'amount' => '60'])
        ->assertSessionHasNoErrors();

    $first = deliveryChargeScreenRule(FeeType::CustomerDeliveryInsideDhaka);

    $this->actingAs($this->admin)
        ->post(route('admin.delivery-charges.store'), ['rate' => 'inside_dhaka', 'amount' => '80.50'])
        ->assertSessionHasNoErrors();

    $current = deliveryChargeScreenRule(FeeType::CustomerDeliveryInsideDhaka);

    expect($current->amount->toDecimal())->toBe('80.50')
        ->and($current->id)->not->toBe($first->id)
        ->and($first->refresh()->is_active)->toBeFalse()
        ->and($first->amount->toDecimal())->toBe('60.00')
        ->and($first->effective_until?->equalTo($current->effective_from))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'billing.fee_rule_created')->count())->toBe(2);
});

it('keeps the three rates apart: Outside Dhaka is the existing website delivery fee, wholesale its own', function () {
    $this->actingAs($this->admin)->post(route('admin.delivery-charges.store'), ['rate' => 'outside_dhaka', 'amount' => '120']);
    $this->actingAs($this->admin)->post(route('admin.delivery-charges.store'), ['rate' => 'wholesale', 'amount' => '200']);

    expect(deliveryChargeScreenRule(FeeType::WebsiteDelivery)?->amount->toDecimal())->toBe('120.00')
        ->and(deliveryChargeScreenRule(FeeType::WholesaleDelivery)?->amount->toDecimal())->toBe('200.00')
        ->and(deliveryChargeScreenRule(FeeType::CustomerDeliveryInsideDhaka))->toBeNull();
});

it('takes zero as free delivery, and deactivates a rate so its area fails closed', function () {
    $this->actingAs($this->admin)->post(route('admin.delivery-charges.store'), ['rate' => 'inside_dhaka', 'amount' => '0']);
    $this->actingAs($this->admin)->post(route('admin.delivery-charges.store'), ['rate' => 'outside_dhaka', 'amount' => '60']);

    $this->actingAs($this->admin)
        ->get(route('admin.delivery-charges.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rates.0.rule.is_free', true)
            ->where('customer_checkout_ready', true));

    $this->actingAs($this->admin)
        ->delete(route('admin.delivery-charges.destroy', deliveryChargeScreenRule(FeeType::CustomerDeliveryInsideDhaka)->public_id))
        ->assertSessionHasNoErrors();

    expect(deliveryChargeScreenRule(FeeType::CustomerDeliveryInsideDhaka))->toBeNull()
        ->and(app(OrderDeliveryCharges::class)->missingRates())->toHaveCount(1);
});

it('refuses a negative or over-precise amount, and anything that is not one of its rates', function () {
    $route = route('admin.delivery-charges.store');

    $this->actingAs($this->admin)->post($route, ['rate' => 'inside_dhaka', 'amount' => '-1'])->assertSessionHasErrors('amount');
    $this->actingAs($this->admin)->post($route, ['rate' => 'inside_dhaka', 'amount' => '10.555'])->assertSessionHasErrors('amount');
    $this->actingAs($this->admin)->post($route, ['rate' => 'registration', 'amount' => '10'])->assertSessionHasErrors('rate');

    $registration = FeeRule::create([
        'fee_type' => FeeType::Registration->value, 'currency_code' => 'BDT',
        'amount' => Money::fromDecimal('500.00'), 'effective_from' => now()->subDay(),
    ]);

    $this->actingAs($this->admin)->delete(route('admin.delivery-charges.destroy', $registration->public_id))->assertNotFound();

    expect(FeeRule::query()->count())->toBe(1)
        ->and($registration->refresh()->is_active)->toBeTrue();
});

it('has a deployment readiness check that fails until both customer rates exist', function () {
    $this->artisan('delivery-charges:check')
        ->expectsOutputToContain(OrderDeliveryCharges::NOT_CONFIGURED_MESSAGE)
        ->assertFailed();

    deliveryTestRate(FeeType::CustomerDeliveryInsideDhaka, '80.00');
    $this->artisan('delivery-charges:check')->assertFailed();

    deliveryTestRate(FeeType::WebsiteDelivery, '0.00');
    $this->artisan('delivery-charges:check')->assertSuccessful();
});

it('is closed to staff without the billing permission, and to partners', function () {
    $outsider = testPlatformStaff(PlatformRole::InventoryManager);

    $this->actingAs($outsider)->get(route('admin.delivery-charges.index'))->assertForbidden();
    $this->actingAs($outsider)->post(route('admin.delivery-charges.store'), ['rate' => 'inside_dhaka', 'amount' => '1'])->assertForbidden();
    $this->actingAs(testBusinessAccount()->owner)->get(route('admin.delivery-charges.index'))->assertForbidden();

    expect(FeeRule::query()->count())->toBe(0);
});

it('never touches an order already placed when a rate changes', function () {
    deliveryTestRate(FeeType::WebsiteDelivery, '60.00');
    $order = Order::factory()->create([
        'delivery' => Money::fromDecimal('60.00'),
        'total' => Money::fromDecimal('1060.00'),
    ]);

    $this->actingAs($this->admin)->post(route('admin.delivery-charges.store'), ['rate' => 'outside_dhaka', 'amount' => '150']);

    expect($order->refresh()->delivery->toDecimal())->toBe('60.00')
        ->and($order->total->toDecimal())->toBe('1060.00')
        // Placed before the snapshot existed: nothing is guessed for it.
        ->and($order->delivery_area)->toBeNull();
});
