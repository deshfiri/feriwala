<?php

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\PurchasedProducts;
use App\Domain\Inventory\Actions\BackfillPartnerStock;
use App\Domain\Inventory\Actions\ReceivePartnerPurchase;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\PartnerStockItem;
use App\Domain\Inventory\Models\PartnerStockMovement;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\PartnerStock;
use App\Domain\Inventory\Queries\PartnerStockAvailability;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Enums\OrderSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The stock a Partner owns because they bought it (D34): what a paid wholesale
 * purchase credits, what a storefront order holds and spends, and the walls
 * between Partners, Variations and central stock.
 */
beforeEach(function () {
    $this->account = websiteTestAccount();
    $this->product = websiteTestProduct();
    $this->stock = app(PartnerStock::class);
    $this->reservations = app(StockReservations::class);
    $this->availability = app(PartnerStockAvailability::class);
});

/**
 * @return array<string, int> the account's buckets for one unit
 */
function partnerStockBuckets($account, $product, ?ProductVariant $variant = null): array
{
    return PartnerStockItem::query()
        ->where('business_account_id', $account->id)
        ->where('product_id', $product->id)
        ->where('product_variant_id', $variant?->id)
        ->firstOrFail()
        ->buckets();
}

describe('what a purchase credits', function () {
    it('gives the partner the units of a paid wholesale purchase, once', function () {
        $order = websiteTestPurchase($this->account, $this->product, quantity: 20);

        expect(partnerStockBuckets($this->account, $this->product))->toMatchArray(['available' => 20, 'reserved' => 0]);

        // The same payment settling again, or a backfill over it, adds nothing.
        expect(app(ReceivePartnerPurchase::class)->handle($order))->toBe(0)
            ->and(app(BackfillPartnerStock::class)->handle()['units'])->toBe(0)
            ->and(partnerStockBuckets($this->account, $this->product)['available'])->toBe(20);
    });

    it('adds a later purchase to the earlier one', function () {
        websiteTestPurchase($this->account, $this->product, quantity: 20);
        websiteTestPurchase($this->account, $this->product, quantity: 5);

        expect(partnerStockBuckets($this->account, $this->product)['available'])->toBe(25);
    });

    it('credits nothing for an unpaid or cancelled purchase', function () {
        websiteTestPurchase($this->account, $this->product, ['paid_at' => null], quantity: 4);
        websiteTestPurchase($this->account, $this->product, ['cancelled_at' => now()], quantity: 6);

        expect(PartnerStockItem::query()->count())->toBe(0)
            ->and($this->availability->forProduct($this->product, $this->account)[0]['quantity'])->toBe(0);
    });

    it('credits nothing for an order that is not a wholesale purchase', function () {
        websiteTestPurchase($this->account, $this->product, ['source' => OrderSource::ManualEntry], quantity: 3);

        expect(PartnerStockItem::query()->count())->toBe(0);
    });

    it('keeps each exact variation, and each partner, apart', function () {
        $medium = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-PJ-M', 'combination_key' => 'm']);
        $large = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-PJ-L', 'combination_key' => 'l']);
        $other = websiteTestAccount();

        websiteTestPurchase($this->account, $this->product, quantity: 7, variant: $medium);
        websiteTestPurchase($other, $this->product, quantity: 50, variant: $medium);

        $answers = $this->availability->forProduct($this->product, $this->account);

        expect(collect($answers)->pluck('quantity', 'sku')->all())->toBe(['FW-PJ-M' => 7, 'FW-PJ-L' => 0])
            ->and($this->availability->forSkus(['fw-pj-m'], $other)['FW-PJ-M']['quantity'])->toBe(50)
            ->and($large->id)->not->toBe($medium->id);
    });

    it('never reads central warehouse stock as the partner\'s own', function () {
        $warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
        StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->product->id, 'available' => 500]);

        expect($this->availability->forProduct($this->product, $this->account)[0])->toMatchArray(['in_stock' => false, 'quantity' => 0]);
    });
});

describe('what a storefront order holds and spends', function () {
    beforeEach(function () {
        websiteTestPurchase($this->account, $this->product, quantity: 20);
    });

    it('reserves, commits and releases through the one reservation lifecycle', function () {
        $held = $this->reservations->reserveFromPartner($this->account, $this->product, null, 2, ReservationKind::OnlinePayment, 'WO-1-L1');
        $cancelled = $this->reservations->reserveFromPartner($this->account, $this->product, null, 3, ReservationKind::OnlinePayment, 'WO-2-L1');

        expect($held->isPartnerSourced())->toBeTrue()
            ->and($held->stock_item_id)->toBeNull()
            ->and(partnerStockBuckets($this->account, $this->product))->toMatchArray(['available' => 15, 'reserved' => 5]);

        $this->reservations->commit($held);
        $this->reservations->release($cancelled, 'Customer cancelled.');

        expect(partnerStockBuckets($this->account, $this->product))
            ->toMatchArray(['available' => 18, 'reserved' => 0, 'processing' => 2])
            ->and($held->refresh()->status)->toBe(StockReservationStatus::Committed)
            ->and($cancelled->refresh()->status)->toBe(StockReservationStatus::Released);
    });

    it('releases once however many times it is asked', function () {
        $held = $this->reservations->reserveFromPartner($this->account, $this->product, null, 4, ReservationKind::OnlinePayment, 'WO-3-L1');

        $this->reservations->release($held);
        $this->reservations->release($held);

        expect(partnerStockBuckets($this->account, $this->product))->toMatchArray(['available' => 20, 'reserved' => 0])
            ->and(PartnerStockMovement::query()->where('type', 'reservation_released')->count())->toBe(1);
    });

    it('answers a retried reservation with the one it already made', function () {
        $first = $this->reservations->reserveFromPartner($this->account, $this->product, null, 4, ReservationKind::OnlinePayment, 'WO-4-L1');
        $again = $this->reservations->reserveFromPartner($this->account, $this->product, null, 4, ReservationKind::OnlinePayment, 'WO-4-L1');

        expect($again->id)->toBe($first->id)
            ->and(partnerStockBuckets($this->account, $this->product)['reserved'])->toBe(4)
            ->and(fn () => $this->reservations->reserveFromPartner($this->account, $this->product, null, 5, ReservationKind::OnlinePayment, 'WO-4-L1'))
            ->toThrow(InventoryRefused::class);
    });

    it('never sells more than the partner has left', function () {
        $this->reservations->reserveFromPartner($this->account, $this->product, null, 18, ReservationKind::OnlinePayment, 'WO-5-L1');

        expect(fn () => $this->reservations->reserveFromPartner($this->account, $this->product, null, 3, ReservationKind::OnlinePayment, 'WO-6-L1'))
            ->toThrow(InventoryRefused::class)
            ->and(partnerStockBuckets($this->account, $this->product))->toMatchArray(['available' => 2, 'reserved' => 18]);
    });

    it('never draws on another partner or another variation', function () {
        $other = websiteTestAccount();
        $medium = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-PJ-M', 'combination_key' => 'm']);

        expect(fn () => $this->reservations->reserveFromPartner($other, $this->product, null, 1, ReservationKind::OnlinePayment, 'WO-7-L1'))
            ->toThrow(InventoryRefused::class)
            ->and(fn () => $this->reservations->reserveFromPartner($this->account, $this->product, $medium, 1, ReservationKind::OnlinePayment, 'WO-8-L1'))
            ->toThrow(InventoryRefused::class)
            ->and(partnerStockBuckets($this->account, $this->product)['available'])->toBe(20);
    });

    it('leaves the history it explains: nothing is rewritten, nothing goes negative', function () {
        $held = $this->reservations->reserveFromPartner($this->account, $this->product, null, 2, ReservationKind::OnlinePayment, 'WO-9-L1');
        $this->reservations->release($held);

        $movement = PartnerStockMovement::query()->where('type', 'reservation')->firstOrFail();

        expect($movement->before['available'])->toBe(20)
            ->and($movement->after['available'])->toBe(18)
            ->and(fn () => DB::table('partner_stock_movements')->where('id', $movement->id)->update(['quantity' => 99]))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::table('partner_stock_items')->update(['available' => -1]))
            ->toThrow(QueryException::class);
    });
});

describe('taking a purchase back', function () {
    it('removes only the units still available, and the product stops counting once none are accounted for', function () {
        websiteTestPurchase($this->account, $this->product, quantity: 10);
        $this->reservations->reserveFromPartner($this->account, $this->product, null, 4, ReservationKind::OnlinePayment, 'WO-10-L1');

        $taken = $this->stock->reverse($this->account, $this->product->id, null, 10);

        expect($taken)->toBe(6)
            ->and(partnerStockBuckets($this->account, $this->product))->toMatchArray(['available' => 0, 'reserved' => 4])
            ->and(app(PurchasedProducts::class)->has($this->account, $this->product))->toBeTrue();

        $this->reservations->release(StockReservation::query()->firstOrFail());
        $this->stock->reverse($this->account, $this->product->id, null, 4);

        expect(app(PurchasedProducts::class)->has($this->account, $this->product))->toBeFalse();
    });
});
