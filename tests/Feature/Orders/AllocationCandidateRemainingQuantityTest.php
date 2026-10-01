<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Queries\AllocationSourceCandidates;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * AllocationSourceCandidates judges eligibility and margin against the
 * line's remaining (unallocated) quantity, not its full quantity (Advanced
 * Order Management batch, Commit 3) -- an unsplit line behaves exactly as
 * before, since remaining equals the full quantity until something is
 * actually allocated.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->order = Order::factory()->create();
    $this->product = websiteTestProduct();
    $supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);

    $this->bigOffer = SupplierOffer::create([
        'supplier_id' => $supplier->id,
        'product_id' => $this->product->id,
        'status' => OfferStatus::Active,
        'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT),
        'platform_rate' => Money::fromDecimal('700.00', Currency::BDT),
        'currency_code' => 'BDT',
        'activated_at' => now(),
        'supply_mode' => SupplyMode::OnDemand->value,
        'fulfilment_capacity' => null,
        'lead_time_days' => null,
    ]);
    supplierTestOfferPriceVersion($this->bigOffer);

    $this->cappedOffer = SupplierOffer::create([
        'supplier_id' => $supplier->id,
        'product_id' => $this->product->id,
        'status' => OfferStatus::Active,
        'supplier_rate' => Money::fromDecimal('520.00', Currency::BDT),
        'platform_rate' => Money::fromDecimal('700.00', Currency::BDT),
        'currency_code' => 'BDT',
        'activated_at' => now(),
        'supply_mode' => SupplyMode::OnDemand->value,
        'fulfilment_capacity' => 5,
        'lead_time_days' => null,
    ]);
    supplierTestOfferPriceVersion($this->cappedOffer);

    $this->line = OrderItem::create([
        'order_id' => $this->order->id,
        'line_number' => 1,
        'product_id' => $this->product->id,
        'sku' => $this->product->sku,
        'product_name' => $this->product->name,
        'quantity' => 10,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('700.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('7000.00', Currency::BDT),
        'line_total' => Money::fromDecimal('7000.00', Currency::BDT),
        'created_at' => now(),
    ]);

    $this->staff = testPlatformStaff(PlatformRole::Admin);
});

test('a capacity-5 offer is ineligible against the full line quantity of 10', function () {
    $candidates = app(AllocationSourceCandidates::class)->forLine($this->line);
    $capped = collect($candidates)->firstWhere('sourceId', $this->cappedOffer->public_id);

    expect($capped->isEligible)->toBeFalse()
        ->and($capped->ineligibleReason)->not->toBeNull();
});

test('the same capacity-5 offer becomes eligible once enough of the line is already allocated elsewhere', function () {
    app(AllocateOrderLineSource::class)->handle(
        $this->line, AllocationSourceType::SupplierOffer, $this->bigOffer->public_id, $this->staff, 'First split.', quantity: 6,
    );

    $candidates = app(AllocationSourceCandidates::class)->forLine($this->line);
    $capped = collect($candidates)->firstWhere('sourceId', $this->cappedOffer->public_id);

    // Only 4 units remain unallocated, which the capacity-5 offer can cover.
    expect($capped->isEligible)->toBeTrue()
        ->and($capped->expectedMargin->toDecimal())->toBe('720.00'); // (700-520) * 4
});

test('an unsplit line judges eligibility against its own full quantity, unchanged from before split allocation', function () {
    $candidates = app(AllocationSourceCandidates::class)->forLine($this->line);
    $big = collect($candidates)->firstWhere('sourceId', $this->bigOffer->public_id);

    expect($big->isEligible)->toBeTrue()
        ->and($big->expectedMargin->toDecimal())->toBe('2000.00'); // (700-500) * 10
});
