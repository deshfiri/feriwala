<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Billing\Enums\GatewayOwnership;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Actions\FreezePlatformReceivable;
use App\Domain\Order\Data\WebsiteOrderQuote;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\ReceivableComponent;
use App\Domain\Order\Exceptions\PlatformReceivableRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderPlatformReceivable;
use App\Domain\Order\Models\OrderPlatformReceivableLine;
use App\Domain\Order\Queries\PlatformReceivableCalculator;
use App\Domain\Tax\Data\TaxBreakdown;
use App\Domain\Tax\Data\TaxCharge;
use App\Domain\Tax\Enums\TaxMode;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * What Banij is owed on a Store Order paid to a Partner's own gateway (D36):
 *
 *     receivable = Σ OrderItem::recoverableAmount() + delivery charge + delivery tax
 *
 * Worked examples, the refusals that stop a figure being guessed, and the
 * guarantee that once frozen it never moves.
 */

beforeEach(function () {
    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->account->forceFill(['account_type' => AccountType::NonConditional])->save();
    $this->website = Website::factory()->active()->forAccount($this->account)->create();
});

function receivableMoney(string $amount): Money
{
    return Money::fromDecimal($amount, Currency::BDT);
}

function receivableTax(string $amount, TaxMode $mode, string $net = '100.00'): TaxCharge
{
    return new TaxCharge('VAT', 'VAT 15%', 1500, $mode, receivableMoney($net), receivableMoney($amount));
}

/**
 * What a website order must carry to exist at all: a customer, a payment, the
 * storefront's own reference, an idempotency key and both addresses.
 *
 * @return array<string, mixed>
 */
function receivableStorefrontFields(string $total): array
{
    $website = test()->website;

    $customer = WebsiteCustomer::create([
        'website_id' => $website->id,
        'mobile' => '+88017'.random_int(10000000, 99999999),
        'name' => 'Fixture customer',
    ]);

    $payment = Payment::create([
        'business_account_id' => test()->account->id,
        'purpose' => PaymentPurpose::WebsiteOrder,
        'status' => PaymentStatus::Initiated,
        'currency_code' => 'BDT',
        'amount' => receivableMoney($total),
        'gateway' => 'sslcommerz',
        'gateway_ownership' => GatewayOwnership::Platform,
        'funds_destination' => 'platform',
        'website_id' => $website->id,
    ]);

    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

    return [
        'website_customer_id' => $customer->id,
        'storefront_order_reference' => 'SF-RCV-'.Str::upper(Str::random(6)),
        'payment_id' => $payment->id,
        'idempotency_key' => (string) Str::uuid(),
        'billing_address' => $address,
        'shipping_address' => $address,
    ];
}

/**
 * A Store Order and its lines, frozen the way placement freezes them.
 *
 * @param  list<array<string, mixed>>  $lines  each: quantity, platform_retained, product_cost, suggested_unit, line_total, tax, tax_included, tax_mode
 */
function receivableOrder(array $lines, string $delivery = '0.00', string $deliveryTaxAdded = '0.00', string $deliveryTaxIncluded = '0.00', array $orderOverrides = []): Order
{
    $account = test()->account;
    $itemTax = array_reduce($lines, fn (string $sum, array $line) => bcadd($sum, $line['tax'] ?? '0.00', 2), '0.00');
    $itemIncluded = array_reduce($lines, fn (string $sum, array $line) => bcadd($sum, $line['tax_included'] ?? '0.00', 2), '0.00');

    $subtotal = array_reduce($lines, fn (string $sum, array $line) => bcadd($sum, $line['line_subtotal'] ?? $line['line_total'], 2), '0.00');
    $addedTax = bcadd($itemTax, $deliveryTaxAdded, 2);
    $total = bcadd(bcadd($subtotal, $delivery, 2), $addedTax, 2);

    $order = Order::create([
        ...receivableStorefrontFields($total),
        'source' => OrderSource::Website,
        'status' => OrderStatus::PaymentPending->value,
        'business_account_id' => $account->id,
        'website_id' => test()->website->id,
        'account_type' => AccountType::NonConditional,
        'customer' => ['contact_name' => 'Fixture', 'mobile' => '01700000000'],
        'currency_code' => 'BDT',
        'subtotal' => receivableMoney($subtotal),
        'discount' => receivableMoney('0.00'),
        'delivery' => receivableMoney($delivery),
        'tax' => receivableMoney($addedTax),
        'tax_included' => receivableMoney(bcadd($itemIncluded, $deliveryTaxIncluded, 2)),
        'total' => receivableMoney($total),
        'placed_at' => now(),
        ...$orderOverrides,
    ]);

    foreach ($lines as $index => $line) {
        $quantity = $line['quantity'] ?? 1;
        $tax = $line['tax'] ?? '0.00';
        $included = $line['tax_included'] ?? '0.00';

        $order->items()->create([
            'line_number' => $index + 1,
            'sku' => 'RCV-'.($index + 1),
            'product_name' => 'Receivable fixture',
            'quantity' => $quantity,
            'currency_code' => 'BDT',
            'unit_price' => receivableMoney(bcdiv($line['line_subtotal'] ?? $line['line_total'], (string) $quantity, 2)),
            'line_subtotal' => receivableMoney($line['line_subtotal'] ?? $line['line_total']),
            'discount' => receivableMoney('0.00'),
            'tax' => receivableMoney($tax),
            'tax_included' => receivableMoney($included),
            'line_total' => receivableMoney($line['line_total']),
            'platform_retained_amount' => isset($line['platform_retained']) ? receivableMoney($line['platform_retained']) : null,
            'product_cost' => isset($line['product_cost']) ? receivableMoney($line['product_cost']) : null,
            'suggested_unit_price' => isset($line['suggested_unit']) ? receivableMoney($line['suggested_unit']) : null,
            'tax_mode' => $line['tax_mode'] ?? null,
        ]);
    }

    return $order->refresh();
}

function receivableQuote(Order $order, TaxCharge $deliveryTax): WebsiteOrderQuote
{
    return new WebsiteOrderQuote(
        lines: [],
        currency: Currency::BDT,
        subtotal: $order->subtotal,
        discount: $order->discount,
        delivery: $order->delivery,
        deliveryTax: $deliveryTax,
        tax: TaxBreakdown::of([], Currency::BDT),
        total: $order->total,
    );
}

function receivableCalculate(Order $order, ?TaxCharge $deliveryTax = null): array
{
    $breakdown = app(PlatformReceivableCalculator::class)->calculate($order, $order->delivery, $deliveryTax ?? TaxCharge::none($order->delivery));

    return [$breakdown->total()->toDecimal(), collect($breakdown->lines)->mapWithKeys(fn ($line) => [$line->key => $line->amount->toDecimal()])->all()];
}

describe('the worked examples', function () {
    it('one line, no tax: the frozen platform amount plus the delivery charge', function () {
        // Sold for 1,200; the platform keeps its suggested 1,000; delivery is 100.
        $order = receivableOrder([['line_total' => '1200.00', 'platform_retained' => '1000.00', 'suggested_unit' => '1000.00']], delivery: '100.00');

        [$total, $lines] = receivableCalculate($order);

        expect($total)->toBe('1100.00')
            ->and($lines)->toBe(['line-1:product_recovery' => '1000.00', 'order:delivery_charge' => '100.00']);
    });

    it('several units: the frozen amount for the whole line, with the exact unit price kept', function () {
        $order = receivableOrder([['quantity' => 3, 'line_total' => '3600.00', 'platform_retained' => '3000.00', 'suggested_unit' => '1000.00']], delivery: '100.00');

        $breakdown = app(PlatformReceivableCalculator::class)->calculate($order, $order->delivery, TaxCharge::none($order->delivery));
        $product = $breakdown->lines[0];

        expect($breakdown->total()->toDecimal())->toBe('3100.00')
            ->and($product->quantity)->toBe(3)
            ->and($product->amount->toDecimal())->toBe('3000.00')
            ->and($product->unitAmount?->toDecimal())->toBe('1000.00');
    });

    it('tax added on top: product tax and delivery tax are each their own component', function () {
        // 2 × 1,200 = 2,400 + 360 tax; delivery 100 + 15 tax.
        $order = receivableOrder(
            [['quantity' => 2, 'line_subtotal' => '2400.00', 'line_total' => '2760.00', 'platform_retained' => '2000.00', 'suggested_unit' => '1000.00', 'tax' => '360.00', 'tax_mode' => 'exclusive']],
            delivery: '100.00',
            deliveryTaxAdded: '15.00',
        );

        [$total, $lines] = receivableCalculate($order, receivableTax('15.00', TaxMode::Exclusive));

        expect($total)->toBe('2475.00')
            ->and($lines)->toBe([
                'line-1:product_recovery' => '2000.00',
                'line-1:product_tax' => '360.00',
                'order:delivery_charge' => '100.00',
                'order:delivery_tax' => '15.00',
            ]);
    });

    it('tax already in the price: the extracted tax is Banij\'s, and counted once', function () {
        // Sold for 1,200 including 156.52 tax; the platform keeps 1,000 plus that tax.
        $order = receivableOrder(
            [['line_total' => '1200.00', 'platform_retained' => '1000.00', 'suggested_unit' => '1000.00', 'tax_included' => '156.52', 'tax_mode' => 'inclusive']],
            delivery: '100.00',
        );

        [$total, $lines] = receivableCalculate($order);

        expect($total)->toBe('1256.52')
            ->and($lines)->toMatchArray(['line-1:product_recovery' => '1000.00', 'line-1:product_tax' => '156.52']);
    });

    it('tax already in the delivery charge: recorded, never added again', function () {
        $order = receivableOrder(
            [['line_total' => '1200.00', 'platform_retained' => '1000.00', 'suggested_unit' => '1000.00']],
            delivery: '100.00',
            deliveryTaxIncluded: '13.04',
        );

        [$total, $lines] = receivableCalculate($order, receivableTax('13.04', TaxMode::Inclusive, '86.96'));

        expect($total)->toBe('1100.00')
            ->and($lines['order:delivery_tax'])->toBe('0.00');
    });

    it('a free delivery still shows the delivery component, at nothing', function () {
        $order = receivableOrder([['line_total' => '1200.00', 'platform_retained' => '1000.00']]);

        [$total, $lines] = receivableCalculate($order);

        expect($total)->toBe('1000.00')
            ->and($lines['order:delivery_charge'])->toBe('0.00');
    });
});

describe('one figure per line, never two', function () {
    it('uses the product cost only when no platform amount was frozen', function () {
        $order = receivableOrder([['line_total' => '4000.00', 'product_cost' => '3000.00']]);

        $breakdown = app(PlatformReceivableCalculator::class)->calculate($order, $order->delivery, TaxCharge::none($order->delivery));

        expect($breakdown->total()->toDecimal())->toBe('3000.00')
            ->and($breakdown->lines[0]->basis)->toBe('product_cost');
    });

    it('never adds the product cost to the platform amount', function () {
        $order = receivableOrder([['line_total' => '2000.00', 'platform_retained' => '1000.00', 'product_cost' => '1500.00']]);

        $breakdown = app(PlatformReceivableCalculator::class)->calculate($order, $order->delivery, TaxCharge::none($order->delivery));

        expect($breakdown->total()->toDecimal())->toBe('1000.00')
            ->and($breakdown->lines[0]->basis)->toBe('platform_retained_amount')
            ->and($breakdown->lines)->toHaveCount(2);
    });

    it('agrees with the canonical recoverable amount for every line', function () {
        $order = receivableOrder([
            ['line_total' => '2760.00', 'line_subtotal' => '2400.00', 'platform_retained' => '2000.00', 'tax' => '360.00'],
            ['line_total' => '1200.00', 'platform_retained' => '1000.00', 'tax_included' => '156.52'],
        ], deliveryTaxAdded: '0.00');

        $canonical = $order->items->reduce(fn (Money $sum, OrderItem $item) => $sum->plus($item->recoverableAmount()), Money::zero(Currency::BDT));
        $breakdown = app(PlatformReceivableCalculator::class)->calculate($order, $order->delivery, TaxCharge::none($order->delivery));

        expect($breakdown->total()->toDecimal())->toBe($canonical->toDecimal());
    });
});

describe('what it refuses rather than guess', function () {
    it('refuses a line with no frozen amount, never counting it as zero', function () {
        $order = receivableOrder([
            ['line_total' => '1200.00', 'platform_retained' => '1000.00'],
            ['line_total' => '500.00'],
        ]);

        expect(fn () => receivableCalculate($order))
            ->toThrow(PlatformReceivableRefused::class, 'Line 2');
    });

    it('refuses a Conditional account', function () {
        $order = receivableOrder([['line_total' => '1200.00', 'platform_retained' => '1000.00']], orderOverrides: ['account_type' => AccountType::Conditional]);

        expect(fn () => receivableCalculate($order))->toThrow(PlatformReceivableRefused::class);
    });

    it('refuses an order that is not a Store Order', function () {
        $order = receivableOrder([['line_total' => '1200.00', 'platform_retained' => '1000.00']], orderOverrides: [
            'source' => OrderSource::ManualEntry,
            'website_customer_id' => null,
            'storefront_order_reference' => null,
        ]);

        expect(fn () => receivableCalculate($order))->toThrow(PlatformReceivableRefused::class, 'Store Order');
    });

    it('refuses a delivery charge that is not the one the order froze', function () {
        $order = receivableOrder([['line_total' => '1200.00', 'platform_retained' => '1000.00']], delivery: '100.00');

        expect(fn () => app(PlatformReceivableCalculator::class)->calculate($order, receivableMoney('120.00'), TaxCharge::none(receivableMoney('120.00'))))
            ->toThrow(PlatformReceivableRefused::class, 'delivery charge');
    });

    it('refuses delivery tax that does not reconcile with the order\'s tax', function () {
        $order = receivableOrder([['line_total' => '1200.00', 'platform_retained' => '1000.00']], delivery: '100.00', deliveryTaxAdded: '15.00');

        // The quote claims a different delivery tax than the order froze.
        expect(fn () => receivableCalculate($order, receivableTax('20.00', TaxMode::Exclusive)))
            ->toThrow(PlatformReceivableRefused::class, 'tax');
    });

    it('refuses an order with no lines', function () {
        $empty = Order::query()->create([
            ...receivableStorefrontFields('0.00'),
            'source' => OrderSource::Website,
            'status' => OrderStatus::PaymentPending->value,
            'business_account_id' => $this->account->id,
            'website_id' => $this->website->id,
            'account_type' => AccountType::NonConditional,
            'customer' => ['contact_name' => 'Fixture'],
            'currency_code' => 'BDT',
            'subtotal' => receivableMoney('0.00'),
            'discount' => receivableMoney('0.00'),
            'delivery' => receivableMoney('0.00'),
            'tax' => receivableMoney('0.00'),
            'tax_included' => receivableMoney('0.00'),
            'total' => receivableMoney('0.00'),
            'placed_at' => now(),
        ]);

        expect(fn () => app(PlatformReceivableCalculator::class)->calculate($empty, $empty->delivery, TaxCharge::none($empty->delivery)))
            ->toThrow(PlatformReceivableRefused::class, 'no lines');
    });
});

describe('freezing it', function () {
    it('writes the total and every component once, with what a reversal needs', function () {
        $order = receivableOrder(
            [['quantity' => 2, 'line_subtotal' => '2400.00', 'line_total' => '2760.00', 'platform_retained' => '2000.00', 'suggested_unit' => '1000.00', 'tax' => '360.00', 'tax_mode' => 'exclusive']],
            delivery: '100.00',
            deliveryTaxAdded: '15.00',
        );

        $receivable = app(FreezePlatformReceivable::class)->handle($order, receivableQuote($order, receivableTax('15.00', TaxMode::Exclusive)));

        expect($receivable->total->toDecimal())->toBe('2475.00')
            ->and($receivable->calculation_version)->toBe('v1')
            ->and($receivable->website_id)->toBe($this->website->id)
            ->and($receivable->lines)->toHaveCount(4)
            ->and($receivable->lines->sum(fn (OrderPlatformReceivableLine $line) => (float) $line->line_amount->toDecimal()))->toBe(2475.0);

        $product = $receivable->lines->firstWhere('component', ReceivableComponent::ProductRecovery);

        expect($product->order_item_id)->toBe($order->items()->sole()->id)
            ->and($product->basis)->toBe('platform_retained_amount')
            ->and($product->quantity)->toBe(2)
            ->and($product->unit_amount->toDecimal())->toBe('1000.00')
            ->and($product->rule_reference)->toBe('order_item.recoverable_amount:platform_retained_amount');

        $delivery = $receivable->lines->firstWhere('component', ReceivableComponent::DeliveryTax);

        expect($delivery->order_item_id)->toBeNull()
            ->and($delivery->tax_mode)->toBe('exclusive')
            ->and($delivery->line_amount->toDecimal())->toBe('15.00');
    });

    it('answers a repeat with the record already there and writes nothing more', function () {
        $order = receivableOrder([['line_total' => '1200.00', 'platform_retained' => '1000.00']], delivery: '100.00');
        $quote = receivableQuote($order, TaxCharge::none($order->delivery));

        $first = app(FreezePlatformReceivable::class)->handle($order, $quote);
        $second = app(FreezePlatformReceivable::class)->handle($order, $quote);

        expect($second->id)->toBe($first->id)
            ->and(OrderPlatformReceivable::query()->count())->toBe(1)
            ->and(OrderPlatformReceivableLine::query()->count())->toBe(2);
    });

    it('is not changed by a later price change', function () {
        $order = receivableOrder([['line_total' => '1200.00', 'platform_retained' => '1000.00']], delivery: '100.00');
        $receivable = app(FreezePlatformReceivable::class)->handle($order, receivableQuote($order, TaxCharge::none($order->delivery)));

        // Nothing about today's prices is read back: the figure is what was frozen.
        expect($receivable->fresh()->total->toDecimal())->toBe('1100.00')
            ->and($receivable->fresh()->lines->pluck('line_amount')->map->toDecimal()->all())->toBe(['1000.00', '100.00']);
    });

    it('can neither be edited nor deleted, in the database', function () {
        $order = receivableOrder([['line_total' => '1200.00', 'platform_retained' => '1000.00']], delivery: '100.00');
        $receivable = app(FreezePlatformReceivable::class)->handle($order, receivableQuote($order, TaxCharge::none($order->delivery)));
        $line = $receivable->lines->first();

        foreach ([
            fn () => DB::table('order_platform_receivables')->where('id', $receivable->id)->update(['total' => '1.00']),
            fn () => DB::table('order_platform_receivables')->where('id', $receivable->id)->delete(),
            fn () => DB::table('order_platform_receivable_lines')->where('id', $line->id)->update(['line_amount' => '1.00']),
            fn () => DB::table('order_platform_receivable_lines')->where('id', $line->id)->delete(),
        ] as $attempt) {
            expect(fn () => DB::transaction($attempt))->toThrow(QueryException::class);
        }

        expect($receivable->fresh()->total->toDecimal())->toBe('1100.00');
    });

    it('refuses a second order\'s worth of components under one key', function () {
        $order = receivableOrder([['line_total' => '1200.00', 'platform_retained' => '1000.00']], delivery: '100.00');
        $receivable = app(FreezePlatformReceivable::class)->handle($order, receivableQuote($order, TaxCharge::none($order->delivery)));

        expect(fn () => DB::transaction(fn () => $receivable->lines()->create([
            'component' => ReceivableComponent::DeliveryCharge,
            'component_key' => 'order:delivery_charge',
            'description' => 'Delivery charge again',
            'quantity' => 1,
            'line_amount' => receivableMoney('100.00'),
            'currency_code' => 'BDT',
            'rule_reference' => 'order.delivery',
        ])))->toThrow(QueryException::class);
    });

    it('leaves every existing order without a receivable: nothing is backfilled', function () {
        $order = receivableOrder([['line_total' => '1200.00', 'platform_retained' => '1000.00']], delivery: '100.00');

        expect($order->fresh())->not->toBeNull()
            ->and(OrderPlatformReceivable::query()->where('order_id', $order->id)->exists())->toBeFalse();
    });
});
