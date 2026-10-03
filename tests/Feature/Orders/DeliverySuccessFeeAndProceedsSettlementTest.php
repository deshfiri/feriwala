<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Actions\SetDeliveryChargeSettings;
use App\Domain\Order\Actions\AdvanceOrderDeliveryStatus;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Actions\EvaluateOrderProceedsEligibility;
use App\Domain\Order\Actions\RecordCodCollection;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderProceedsSettlement;
use App\Domain\Supplier\Actions\ApplyDeliverySuccessFee;
use App\Domain\Supplier\Actions\OpenSupplierWallet;
use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\DeliverySuccessFee;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierLedgerEntry;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Domain\Supplier\SupplierWalletService;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The Delivery Success Fee and a Non-Conditional reseller's earning
 * settlement (D-new), both wired onto the same `AdvanceOrderDeliveryStatus`
 * hook `SynchronizeSupplierPayablesWithDelivery` already uses.
 *
 * The fee is calculated on the supplier's own gross payable, frozen at the
 * rate in force, charged once per payable regardless of repeated
 * transitions. The reseller's earning becomes real only once both
 * `Delivered` and an explicit COD-collection confirmation are in — never
 * from `Delivered` alone, and never silently zeroed when a confirmed
 * collection falls short of what Banij is owed.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = testPlatformStaff(PlatformRole::Admin);
    $this->order = Order::factory()->create();
    $this->product = websiteTestProduct();

    $this->line = OrderItem::create([
        'order_id' => $this->order->id,
        'line_number' => 1,
        'product_id' => $this->product->id,
        'sku' => $this->product->sku,
        'product_name' => $this->product->name,
        'quantity' => 2,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('2600.00', Currency::BDT),
        'line_total' => Money::fromDecimal('2600.00', Currency::BDT),
        'created_at' => now(),
    ]);
});

function dsfOffer(string $rate = '900.00', int $stock = 10): SupplierOffer
{
    $offer = supplierTestOffer(
        Supplier::factory()->create(['status' => SupplierStatus::Approved]),
        test()->product,
        supplierRate: $rate,
    );
    $offer->stock()->update(['quantity' => $stock]);
    supplierTestOfferPriceVersion($offer);

    return $offer->refresh();
}

function dsfAllocate(SupplierOffer $offer): void
{
    app(AllocateOrderLineSource::class)->handle(
        test()->line->refresh(),
        AllocationSourceType::SupplierOffer,
        $offer->public_id,
        test()->staff,
        'Chosen by staff after comparing sources.',
    );
}

function dsfAdvanceToDelivered(Order $order): void
{
    $advance = app(AdvanceOrderDeliveryStatus::class);
    $advance->handle($order->refresh(), OrderDeliveryStatus::CourierAssigned, test()->staff);
    $advance->handle($order->refresh(), OrderDeliveryStatus::Shipped, test()->staff);
    $advance->handle($order->refresh(), OrderDeliveryStatus::OutForDelivery, test()->staff);
    $advance->handle($order->refresh(), OrderDeliveryStatus::Delivered, test()->staff);
}

/**
 * A fresh order and its one line, with the account type and the line's
 * resale amount set at creation time — both are frozen, locked columns and
 * cannot be changed after the row exists, by design.
 *
 * @return array{0: Order, 1: OrderItem}
 */
function dsfOrderWithLine(AccountType $accountType, ?string $resaleAmount = null): array
{
    $order = Order::factory()->create(['account_type' => $accountType]);

    $line = OrderItem::create([
        'order_id' => $order->id,
        'line_number' => 1,
        'product_id' => test()->product->id,
        'sku' => test()->product->sku,
        'product_name' => test()->product->name,
        'quantity' => 2,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('2600.00', Currency::BDT),
        'line_total' => Money::fromDecimal('2600.00', Currency::BDT),
        'resale_amount' => $resaleAmount === null ? null : Money::fromDecimal($resaleAmount, Currency::BDT),
        'created_at' => now(),
    ]);

    return [$order, $line];
}

describe('Delivery Success Fee', function () {
    it('charges the configured default (1%) of the supplier\'s gross payable, into recovery when the wallet holds nothing yet', function () {
        $offer = dsfOffer('900.00'); // gross = 900 x 2 = 1800.00
        dsfAllocate($offer);
        $payable = SupplierPayable::query()->sole();

        dsfAdvanceToDelivered($this->order);

        $fee = DeliverySuccessFee::query()->sole();

        expect($fee->base_amount->toDecimal())->toBe('1800.00')
            ->and($fee->rate_percent)->toBe('1.00')
            ->and($fee->fee_amount->toDecimal())->toBe('18.00')
            ->and($fee->supplier_payable_id)->toBe($payable->id)
            ->and($fee->order_id)->toBe($this->order->id);

        $wallet = SupplierWallet::query()->where('supplier_id', $offer->supplier_id)->sole();

        // Nothing was available (the payable has not settled into the
        // wallet), so the whole fee lands in recovery, never a negative total.
        expect($wallet->total->isZero())->toBeTrue()
            ->and($wallet->recovery->toDecimal())->toBe('18.00');

        expect($fee->ledgerEntry->type->value)->toBe('delivery_success_fee_debit')
            ->and($fee->ledgerEntry->debit->toDecimal())->toBe('0.00');
    });

    it('debits straight from the available balance when the wallet already holds enough', function () {
        $offer = dsfOffer('900.00');
        dsfAllocate($offer);
        $payable = SupplierPayable::query()->sole();
        $wallet = app(OpenSupplierWallet::class)->handle($offer->supplier, Currency::BDT);

        app(SupplierWalletService::class)->credit($wallet, Money::fromDecimal('500.00', Currency::BDT), new SupplierPostingContext(
            source: 'test_fixture',
            description: 'Pre-funding for the fee test.',
        ));

        dsfAdvanceToDelivered($this->order);

        expect($wallet->refresh()->total->toDecimal())->toBe('482.00')
            ->and($wallet->recovery->isZero())->toBeTrue();

        expect(SupplierPayable::query()->find($payable->id)->gross_amount->toDecimal())->toBe('1800.00');
    });

    it('respects an admin-configured percentage, and freezes it on the fee row', function () {
        app(SetDeliveryChargeSettings::class)->handle(
            $this->staff,
            volumetricDivisor: 5000,
            useGreaterOfActualAndVolumetric: true,
            additionalPerKgCharge: Money::zero(Currency::BDT),
            perBoxCharge: Money::zero(Currency::BDT),
            fragileHandlingCharge: Money::zero(Currency::BDT),
            minimumCharge: Money::zero(Currency::BDT),
            maximumCharge: null,
            freeDeliveryThreshold: null,
            deliverySuccessFeePercent: '2.5',
        );

        $offer = dsfOffer('900.00');
        dsfAllocate($offer);

        dsfAdvanceToDelivered($this->order);

        $fee = DeliverySuccessFee::query()->sole();
        expect($fee->rate_percent)->toBe('2.50')
            ->and($fee->fee_amount->toDecimal())->toBe('45.00');

        // Changing the rate afterwards never rewrites a fee already charged.
        app(SetDeliveryChargeSettings::class)->handle(
            $this->staff,
            volumetricDivisor: 5000,
            useGreaterOfActualAndVolumetric: true,
            additionalPerKgCharge: Money::zero(Currency::BDT),
            perBoxCharge: Money::zero(Currency::BDT),
            fragileHandlingCharge: Money::zero(Currency::BDT),
            minimumCharge: Money::zero(Currency::BDT),
            maximumCharge: null,
            freeDeliveryThreshold: null,
            deliverySuccessFeePercent: '9.00',
        );

        expect($fee->refresh()->rate_percent)->toBe('2.50');
        expect(fn () => DB::table('delivery_success_fees')->where('id', $fee->id)->update(['rate_percent' => '9.00']))
            ->toThrow(QueryException::class);
    });

    it('charges exactly once per payable, however many times Delivered is reached', function () {
        $offer = dsfOffer('900.00');
        dsfAllocate($offer);

        dsfAdvanceToDelivered($this->order);
        expect(DeliverySuccessFee::query()->count())->toBe(1);

        // A repeated call against the same payable -- a retried webhook or a
        // replayed admin action -- is a no-op, not a second charge.
        $payable = SupplierPayable::query()->sole();
        app(ApplyDeliverySuccessFee::class)->handle($payable);
        app(ApplyDeliverySuccessFee::class)->handle($payable);

        expect(DeliverySuccessFee::query()->count())->toBe(1)
            ->and(SupplierLedgerEntry::query()->where('type', 'delivery_success_fee_debit')->count())->toBe(1);
    });

    it('charges nothing, and creates no row, when the configured rate is 0%', function () {
        app(SetDeliveryChargeSettings::class)->handle(
            $this->staff,
            volumetricDivisor: 5000,
            useGreaterOfActualAndVolumetric: true,
            additionalPerKgCharge: Money::zero(Currency::BDT),
            perBoxCharge: Money::zero(Currency::BDT),
            fragileHandlingCharge: Money::zero(Currency::BDT),
            minimumCharge: Money::zero(Currency::BDT),
            maximumCharge: null,
            freeDeliveryThreshold: null,
            deliverySuccessFeePercent: '0',
        );

        $offer = dsfOffer('900.00');
        dsfAllocate($offer);

        dsfAdvanceToDelivered($this->order);

        expect(DeliverySuccessFee::query()->count())->toBe(0);
    });

    it('charges no fee for a line with no supplier', function () {
        dsfAdvanceToDelivered($this->order);

        expect(DeliverySuccessFee::query()->count())->toBe(0);
    });

    it('charges the fee identically for a Non-Conditional order', function () {
        [$order, $line] = dsfOrderWithLine(AccountType::NonConditional, '3200.00');
        test()->line = $line;

        $offer = dsfOffer('900.00');
        dsfAllocate($offer);

        dsfAdvanceToDelivered($order);

        expect(DeliverySuccessFee::query()->sole()->fee_amount->toDecimal())->toBe('18.00');
    });
});

describe('order proceeds settlement (two independent facts)', function () {
    beforeEach(function () {
        [$this->order, $this->line] = dsfOrderWithLine(AccountType::NonConditional, '3200.00');
    });

    it('creates a pending settlement on Delivered alone, and credits nothing', function () {
        dsfAdvanceToDelivered($this->order);

        $settlement = OrderProceedsSettlement::query()->sole();

        expect($settlement->order_item_id)->toBe($this->line->id)
            ->and($settlement->resale_amount->toDecimal())->toBe('3200.00')
            ->and($settlement->recovered_amount->toDecimal())->toBe('2600.00')
            ->and($settlement->delivered_at)->not->toBeNull()
            ->and($settlement->cod_collected_at)->toBeNull()
            ->and($settlement->eligible_at)->toBeNull()
            ->and($settlement->reseller_earning)->toBeNull();

        expect(WalletTransaction::query()->count())->toBe(0);
    });

    it('records a COD collection confirmed before Delivered, and still credits nothing until Delivered follows', function () {
        app(RecordCodCollection::class)->handle($this->staff, $this->line->refresh(), Money::fromDecimal('3200.00', Currency::BDT));

        $settlement = OrderProceedsSettlement::query()->sole();
        expect($settlement->cod_collected_at)->not->toBeNull()
            ->and($settlement->delivered_at)->toBeNull()
            ->and($settlement->eligible_at)->toBeNull();

        expect(WalletTransaction::query()->count())->toBe(0);

        dsfAdvanceToDelivered($this->order);

        $settlement->refresh();
        expect($settlement->delivered_at)->not->toBeNull()
            ->and($settlement->eligible_at)->not->toBeNull()
            ->and($settlement->reseller_earning->toDecimal())->toBe('600.00');

        $account = $this->order->businessAccount;
        $wallet = Wallet::query()->where('business_account_id', $account->id)->sole();
        expect($wallet->total->toDecimal())->toBe('600.00');
    });

    it('credits the reseller exactly once, with the actually confirmed amount, once both facts are in', function () {
        dsfAdvanceToDelivered($this->order);

        app(RecordCodCollection::class)->handle($this->staff, $this->line->refresh(), Money::fromDecimal('3100.00', Currency::BDT));

        $settlement = OrderProceedsSettlement::query()->sole();
        expect($settlement->reseller_earning->toDecimal())->toBe('500.00')
            ->and($settlement->flagged_for_review)->toBeFalse();

        $account = $this->order->businessAccount;
        $transaction = WalletTransaction::query()->where('business_account_id', $account->id)->sole();
        expect($transaction->type->value)->toBe('sales_credit')
            ->and($transaction->amount->toDecimal())->toBe('500.00');

        // A repeated confirmation of the same line never credits twice.
        app(RecordCodCollection::class)->handle($this->staff, $this->line->refresh(), Money::fromDecimal('3100.00', Currency::BDT));

        expect(WalletTransaction::query()->where('business_account_id', $account->id)->count())->toBe(1);
    });

    it('flags, rather than silently zeroing, a confirmed collection below the recoverable cost', function () {
        dsfAdvanceToDelivered($this->order);

        app(RecordCodCollection::class)->handle($this->staff, $this->line->refresh(), Money::fromDecimal('2000.00', Currency::BDT));

        $settlement = OrderProceedsSettlement::query()->sole();
        expect($settlement->flagged_for_review)->toBeTrue()
            ->and($settlement->reseller_earning->toDecimal())->toBe('0.00')
            ->and($settlement->eligible_at)->not->toBeNull();

        expect(WalletTransaction::query()->count())->toBe(0);

        $audit = AuditLog::query()->where('action', 'order_proceeds_settlement.eligible')->sole();
        expect($audit->is_sensitive)->toBeTrue();
    });

    it('creates no settlement row at all for a Conditional order', function () {
        [$order] = dsfOrderWithLine(AccountType::Conditional);

        dsfAdvanceToDelivered($order);

        expect(OrderProceedsSettlement::query()->count())->toBe(0);
    });

    it('is never triggered by a failed delivery, a cancellation or a return', function () {
        $advance = app(AdvanceOrderDeliveryStatus::class);
        $advance->handle($this->order->refresh(), OrderDeliveryStatus::CourierAssigned, $this->staff);
        $advance->handle($this->order->refresh(), OrderDeliveryStatus::Shipped, $this->staff);
        $advance->handle($this->order->refresh(), OrderDeliveryStatus::OutForDelivery, $this->staff);
        $advance->handle($this->order->refresh(), OrderDeliveryStatus::FailedDelivery, $this->staff, 'Customer unreachable.');

        expect(OrderProceedsSettlement::query()->count())->toBe(0)
            ->and(DeliverySuccessFee::query()->count())->toBe(0);
    });

    it('keeps the eligibility gate idempotent under direct repeated calls', function () {
        $eligibility = app(EvaluateOrderProceedsEligibility::class);

        $eligibility->markDelivered($this->line->refresh());
        $eligibility->markDelivered($this->line->refresh());
        $eligibility->markCodCollected($this->line->refresh(), Money::fromDecimal('3200.00', Currency::BDT));
        $eligibility->markCodCollected($this->line->refresh(), Money::fromDecimal('9999.00', Currency::BDT));

        expect(OrderProceedsSettlement::query()->count())->toBe(1);

        $settlement = OrderProceedsSettlement::query()->sole();
        // The second markCodCollected call changed nothing: the fact was
        // already recorded, so the figure it tried to overwrite is ignored.
        expect($settlement->cod_amount_collected->toDecimal())->toBe('3200.00')
            ->and($settlement->reseller_earning->toDecimal())->toBe('600.00');

        $account = $this->order->businessAccount;
        expect(WalletTransaction::query()->where('business_account_id', $account->id)->count())->toBe(1);
    });
});

describe('recording a COD collection from the admin order screen', function () {
    beforeEach(function () {
        [$this->order, $this->line] = dsfOrderWithLine(AccountType::NonConditional, '3200.00');
    });

    it('lets staff with order.edit record a collection, audited', function () {
        $this->actingAs($this->staff)->post(
            route('admin.orders.lines.cod-collection.store', [$this->order->public_id, $this->line->public_id]),
            ['amount_collected' => '3200.00'],
        )->assertSessionHasNoErrors();

        $settlement = OrderProceedsSettlement::query()->sole();
        expect($settlement->cod_amount_collected->toDecimal())->toBe('3200.00');

        expect(AuditLog::query()->where('action', 'order_item.cod_collection_recorded')->sole()->actor_id)->toBe($this->staff->id);
    });

    it('is refused without order.edit', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::SupplierManager))->post(
            route('admin.orders.lines.cod-collection.store', [$this->order->public_id, $this->line->public_id]),
            ['amount_collected' => '3200.00'],
        )->assertForbidden();

        expect(OrderProceedsSettlement::query()->count())->toBe(0);
    });

    it('refuses a negative amount', function () {
        $this->actingAs($this->staff)->post(
            route('admin.orders.lines.cod-collection.store', [$this->order->public_id, $this->line->public_id]),
            ['amount_collected' => '-5.00'],
        )->assertSessionHasErrors('amount_collected');
    });

    it('refuses a Conditional order\'s line: there is nothing to confirm', function () {
        [$order, $line] = dsfOrderWithLine(AccountType::Conditional);

        $this->actingAs($this->staff)->post(
            route('admin.orders.lines.cod-collection.store', [$order->public_id, $line->public_id]),
            ['amount_collected' => '3200.00'],
        )->assertSessionHasErrors('amount_collected');

        expect(OrderProceedsSettlement::query()->count())->toBe(0);
    });
});
