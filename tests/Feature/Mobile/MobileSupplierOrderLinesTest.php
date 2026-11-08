<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
 * The mobile counterparts of the Supplier web app's allocations, payables and
 * fulfilment screens: the same records, the same self-scoping, the same
 * legal moves. One Supplier must never reach another's order line.
 */

function mobileSupplierOrderLinesCommitment(Supplier $supplier, string $productName = 'Mobile Lines Product'): SupplierFulfilmentCommitment
{
    $product = websiteTestProduct(['name' => $productName]);

    $offer = SupplierOffer::create([
        'supplier_id' => $supplier->id,
        'product_id' => $product->id,
        'status' => OfferStatus::Active,
        'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT),
        'platform_rate' => Money::fromDecimal('700.00', Currency::BDT),
        'currency_code' => 'BDT',
        'activated_at' => now(),
        'supply_mode' => SupplyMode::OnDemand->value,
        'fulfilment_capacity' => null,
        'lead_time_days' => null,
    ]);
    supplierTestOfferPriceVersion($offer);

    $line = OrderItem::create([
        'order_id' => Order::factory()->create()->id,
        'line_number' => 1,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'product_name' => $product->name,
        'quantity' => 1,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('700.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('700.00', Currency::BDT),
        'line_total' => Money::fromDecimal('700.00', Currency::BDT),
        'created_at' => now(),
    ]);

    app(AllocateOrderLineSource::class)->handle(
        $line, AllocationSourceType::SupplierOffer, $offer->public_id, testPlatformStaff(PlatformRole::Admin), 'On-demand allocation.',
    );

    return SupplierFulfilmentCommitment::query()->whereHas('offer', fn ($query) => $query->where('supplier_id', $supplier->id))->sole();
}

/**
 * A line as a website order leaves it: the Supplier is stamped on the line when it
 * is created, because an order line can never be edited afterwards.
 */
function mobileSupplierOrderLinesStamped(SupplierOffer $offer): OrderItem
{
    return OrderItem::create([
        'order_id' => Order::factory()->create()->id,
        'line_number' => 1,
        'product_id' => $offer->product_id,
        'sku' => $offer->product->sku,
        'product_name' => $offer->product->name,
        'quantity' => 1,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('700.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('700.00', Currency::BDT),
        'line_total' => Money::fromDecimal('700.00', Currency::BDT),
        'supplier_id' => $offer->supplier_id,
        'supplier_offer_id' => $offer->id,
        'supplier_offer_price_change_id' => $offer->priceHistory()->latest('id')->firstOrFail()->id,
        'supplier_allocated_quantity' => 1,
        'supplier_rate' => $offer->supplier_rate,
        'platform_rate' => $offer->platform_rate,
        'platform_margin' => $offer->platform_rate->minus($offer->supplier_rate),
        'supplier_currency_code' => 'BDT',
        'supplier_allocated_at' => now(),
        'created_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->supplier = Supplier::factory()->create();
    $this->commitment = mobileSupplierOrderLinesCommitment($this->supplier);
    $this->item = $this->commitment->allocation->orderItem;
    $this->other = Supplier::factory()->create();
});

describe('allocations', function () {
    test('a supplier lists and opens only its own allocated lines', function () {
        $theirs = mobileSupplierOrderLinesStamped(
            mobileSupplierOrderLinesCommitment($this->other, 'Another Supplier Product')->offer,
        );
        $mine = mobileSupplierOrderLinesStamped($this->commitment->offer);

        Sanctum::actingAs($this->supplier);

        $this->getJson(route('mobile.v1.supplier.allocations.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->public_id)
            ->assertJsonPath('data.0.supplier_rate.amount', '500.00')
            ->assertJsonMissingPath('data.0.platform_rate');

        $this->getJson(route('mobile.v1.supplier.allocations.show', $mine->public_id))
            ->assertOk()
            ->assertJsonPath('data.id', $mine->public_id)
            ->assertJsonPath('data.product_name', $mine->product_name);

        $this->getJson(route('mobile.v1.supplier.allocations.show', $theirs->public_id))->assertNotFound();
    });

    test('another supplier\'s allocated line is a 404', function () {
        Sanctum::actingAs($this->other);

        $this->getJson(route('mobile.v1.supplier.allocations.show', $this->item->public_id))->assertNotFound();
    });

    test('allocations need a token and an operational supplier', function () {
        $this->getJson(route('mobile.v1.supplier.allocations.index'))->assertUnauthorized();

        Sanctum::actingAs(Supplier::factory()->suspended()->create());

        $this->getJson(route('mobile.v1.supplier.allocations.index'))
            ->assertForbidden()
            ->assertJsonPath('code', 'supplier_not_operational');
    });
});

describe('payables', function () {
    beforeEach(function () {
        $this->payable = SupplierPayable::query()->where('supplier_id', $this->supplier->id)->firstOrFail();
    });

    test('a supplier lists, filters and opens only its own payables', function () {
        Sanctum::actingAs($this->supplier);

        $this->getJson(route('mobile.v1.supplier.payables.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->payable->public_id)
            ->assertJsonPath('data.0.gross_amount.amount', '500.00')
            ->assertJsonStructure(['statuses' => [['value', 'label']]]);

        $this->getJson(route('mobile.v1.supplier.payables.index', ['status' => 'settled']))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson(route('mobile.v1.supplier.payables.show', $this->payable->public_id))
            ->assertOk()
            ->assertJsonPath('data.reference', $this->payable->reference)
            ->assertJsonStructure(['data' => ['reversals', 'history']]);
    });

    test('an unknown status filter is refused', function () {
        Sanctum::actingAs($this->supplier);

        $this->getJson(route('mobile.v1.supplier.payables.index', ['status' => 'bogus']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    });

    test('another supplier\'s payable is a 404 and never in its list', function () {
        Sanctum::actingAs($this->other);

        $this->getJson(route('mobile.v1.supplier.payables.show', $this->payable->public_id))->assertNotFound();
        $this->getJson(route('mobile.v1.supplier.payables.index'))->assertOk()->assertJsonCount(0, 'data');
    });

    test('payables need a token and an operational supplier', function () {
        $this->getJson(route('mobile.v1.supplier.payables.index'))->assertUnauthorized();

        Sanctum::actingAs(Supplier::factory()->suspended()->create());

        $this->getJson(route('mobile.v1.supplier.payables.index'))->assertForbidden();
    });
});

describe('fulfilment', function () {
    test('a supplier sees only its own commitments', function () {
        mobileSupplierOrderLinesCommitment($this->other, 'Another Supplier Product');

        Sanctum::actingAs($this->supplier);

        $this->getJson(route('mobile.v1.supplier.fulfilment.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->commitment->public_id);

        $this->getJson(route('mobile.v1.supplier.fulfilment.show', $this->commitment->public_id))
            ->assertOk()
            ->assertJsonPath('data.status', $this->commitment->status->value)
            ->assertJsonStructure(['data' => ['available_actions', 'history']]);
    });

    test('confirm, start preparing and mark ready follow the same legal moves as the web', function () {
        Sanctum::actingAs($this->supplier);

        foreach (['confirm' => 'confirmed', 'start_preparing' => 'preparing', 'mark_ready' => 'ready'] as $action => $status) {
            $this->postJson(route('mobile.v1.supplier.fulfilment.advance', $this->commitment->public_id), ['action' => $action])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }

        expect($this->commitment->refresh()->status->value)->toBe('ready');

        // A terminal commitment offers nothing and refuses a further move.
        $this->postJson(route('mobile.v1.supplier.fulfilment.advance', $this->commitment->public_id), ['action' => 'confirm'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');
    });

    test('declining needs a reason and is recorded with the supplier as its source', function () {
        Sanctum::actingAs($this->supplier);

        $this->postJson(route('mobile.v1.supplier.fulfilment.advance', $this->commitment->public_id), ['action' => 'decline'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->postJson(route('mobile.v1.supplier.fulfilment.advance', $this->commitment->public_id), [
            'action' => 'decline',
            'reason' => 'Can no longer source this item.',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');

        expect($this->commitment->refresh()->cancelled_reason)->toBe('Can no longer source this item.')
            ->and($this->commitment->statusHistory->last()->source->value)->toBe('supplier');
    });

    test('a supplier cannot mark a commitment failed or send an unknown action', function () {
        Sanctum::actingAs($this->supplier);

        $this->postJson(route('mobile.v1.supplier.fulfilment.advance', $this->commitment->public_id), ['action' => 'fail', 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');

        expect($this->commitment->refresh()->status->value)->not->toBe('failed');
    });

    test('another supplier cannot open or act on a commitment', function () {
        Sanctum::actingAs($this->other);

        $this->getJson(route('mobile.v1.supplier.fulfilment.show', $this->commitment->public_id))->assertNotFound();
        $this->postJson(route('mobile.v1.supplier.fulfilment.advance', $this->commitment->public_id), ['action' => 'confirm'])->assertNotFound();

        expect($this->commitment->refresh()->status->value)->toBe('awaiting_confirmation');
    });

    test('fulfilment needs a token and an operational supplier', function () {
        $this->getJson(route('mobile.v1.supplier.fulfilment.index'))->assertUnauthorized();

        Sanctum::actingAs(Supplier::factory()->create(['status' => SupplierStatus::Suspended]));

        $this->postJson(route('mobile.v1.supplier.fulfilment.advance', $this->commitment->public_id), ['action' => 'confirm'])
            ->assertForbidden()
            ->assertJsonPath('code', 'supplier_not_operational');
    });
});
