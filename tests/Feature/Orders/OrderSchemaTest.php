<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use App\Domain\Wholesale\Models\Cart;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The order schema (P6-1, P6-2, §18).
 *
 * Every field §18 makes mandatory, every source §18.1 names, and the guarantees
 * the rest of the system leans on held by the database itself: one payment one
 * order, one order per checkout, figures that add up and cannot be rewritten, a
 * status that moves only along the map, lines that are snapshots, and no order
 * ever deleted.
 */

function orderSchemaProduct(): Product
{
    return Product::create([
        'name' => 'Electric kettle',
        'sku' => 'FW-KT-'.Str::upper(Str::random(6)),
        'category_id' => Category::create(['name' => 'Kitchen '.Str::random(6)])->id,
        'wholesale_price' => Money::fromDecimal('2000.00'),
        'status' => ProductStatus::Active,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function orderSchemaLine(Order $order, Product $product, array $overrides = []): array
{
    return [
        'public_id' => (string) Str::ulid(),
        'order_id' => $order->id,
        'line_number' => 1,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'product_name' => $product->name,
        'quantity' => 10,
        'currency_code' => 'BDT',
        'unit_price' => '100.00',
        'line_subtotal' => '1000.00',
        'discount' => '50.00',
        'tax' => '142.50',
        'line_total' => '1092.50',
        'created_at' => now(),
        ...$overrides,
    ];
}

it('keeps every field §18 makes mandatory on a wholesale order', function () {
    $order = Order::factory()->create()->refresh();

    expect($order->reference)->toStartWith('ORD-')
        ->and($order->public_id)->not->toBeEmpty()
        ->and($order->source)->toBe(OrderSource::ErpWholesale)
        ->and($order->status)->toBe(OrderStatus::PaymentPending)
        ->and($order->businessAccount)->not->toBeNull()
        ->and($order->placedBy?->id)->toBe($order->businessAccount->owner_id)
        ->and($order->website_id)->toBeNull()
        ->and($order->payment?->purpose)->toBe(PaymentPurpose::WholesaleOrder)
        ->and($order->customer)->toHaveKeys(['business_name', 'contact_name', 'email', 'mobile'])
        ->and($order->billing_address)->toHaveKey('city', 'Dhaka')
        ->and($order->shipping_address)->toHaveKey('country', 'BD')
        ->and($order->total->toDecimal())->toBe('1000.00')
        ->and($order->fulfillment_status)->toBe(OrderFulfillmentStatus::Unfulfilled)
        ->and($order->courier_status)->toBe(OrderCourierStatus::Unassigned)
        ->and($order->delivery_status)->toBe(OrderDeliveryStatus::NotShipped)
        ->and($order->placed_at)->not->toBeNull();
});

it('types every source §18.1 names', function (OrderSource $source) {
    $order = Order::factory()->create(match ($source) {
        OrderSource::ErpWholesale => [],
        // A website order carries what only it carries (P5-23).
        OrderSource::Website => orderSchemaWebsiteOrder(),
        default => ['source' => $source, 'payment_id' => null, 'idempotency_key' => null],
    });

    expect($order->refresh()->source)->toBe($source);
})->with(OrderSource::cases());

/**
 * The columns a website order is complete with: its shop, its customer and the
 * storefront's own reference, and nobody at the account placing it.
 *
 * @return array<string, mixed>
 */
function orderSchemaWebsiteOrder(): array
{
    $account = testBusinessAccount(AccountStatus::Active);
    $website = Website::factory()->forAccount($account)->active()->create();

    $customer = WebsiteCustomer::create([
        'website_id' => $website->id,
        'mobile' => '+88017'.random_int(10000000, 99999999),
        'name' => 'Ayesha Rahman',
    ]);

    return [
        'source' => OrderSource::Website,
        'business_account_id' => $account->id,
        'website_id' => $website->id,
        'website_customer_id' => $customer->id,
        'storefront_order_reference' => 'SF-'.Str::upper(Str::random(8)),
        'placed_by' => null,
        'cart_id' => null,
    ];
}

it('refuses a source §18.1 does not name', function () {
    $order = Order::factory()->create();

    expect(fn () => DB::table('orders')->insert([
        ...collect(DB::table('orders')->where('id', $order->id)->first())->except(['id', 'public_id', 'reference', 'payment_id', 'idempotency_key'])->all(),
        'public_id' => (string) Str::ulid(),
        'reference' => 'ORD-FAKE-'.Str::upper(Str::random(6)),
        'source' => 'carrier_pigeon',
    ]))->toThrow(QueryException::class, 'orders_source_known');
});

it('refuses a wholesale order missing what it must carry', function (array $missing) {
    expect(fn () => Order::factory()->create($missing))->toThrow(QueryException::class, 'orders_wholesale_is_complete');
})->with([
    'its payment' => [['payment_id' => null]],
    'a billing address' => [['billing_address' => null]],
    'a shipping address' => [['shipping_address' => null]],
    'who placed it' => [['placed_by' => null]],
    'its idempotency key' => [['idempotency_key' => null]],
]);

it('refuses a wholesale order that names a website', function () {
    $account = testBusinessAccount(AccountStatus::Active);
    $website = Website::factory()->forAccount($account)->active()->create();

    expect(fn () => Order::factory()->create([
        'business_account_id' => $account->id,
        'website_id' => $website->id,
    ]))->toThrow(QueryException::class, 'orders_wholesale_is_complete');
});

describe('one payment, one order', function () {
    it('refuses a second order for the same payment', function () {
        $order = Order::factory()->create();

        expect(fn () => Order::factory()->create([
            'business_account_id' => $order->business_account_id,
            'payment_id' => $order->payment_id,
        ]))->toThrow(QueryException::class, 'payment_id');
    });

    it('refuses a second wholesale payment for the same order', function () {
        $order = Order::factory()->create();
        $order->payment?->payable()->associate($order)->save();

        expect(fn () => Payment::create([
            'business_account_id' => $order->business_account_id,
            'purpose' => PaymentPurpose::WholesaleOrder,
            'status' => PaymentStatus::Draft,
            'currency_code' => 'BDT',
            'amount' => Money::fromDecimal('1000.00'),
            'payable_type' => $order->getMorphClass(),
            'payable_id' => $order->id,
        ]))->toThrow(QueryException::class, 'payments_one_wholesale_payment_per_order');
    });

    it('refuses a second order for the same checkout', function () {
        $order = Order::factory()->create();

        expect(fn () => Order::factory()->create([
            'business_account_id' => $order->business_account_id,
            'idempotency_key' => $order->idempotency_key,
        ]))->toThrow(QueryException::class, 'idempotency_key');
    });

    it('keeps at most one wholesale order waiting for payment per cart', function () {
        $first = Order::factory()->create();
        $cart = Cart::create(['user_id' => $first->placed_by, 'business_account_id' => $first->business_account_id]);
        DB::table('orders')->where('id', $first->id)->update(['cart_id' => $cart->id]);

        expect(fn () => Order::factory()->create([
            'business_account_id' => $first->business_account_id,
            'cart_id' => $cart->id,
        ]))->toThrow(QueryException::class, 'orders_one_payment_pending_per_cart');
    });

    it('lets a cart place another order once the earlier one is no longer waiting', function () {
        $first = Order::factory()->create();
        $cart = Cart::create(['user_id' => $first->placed_by, 'business_account_id' => $first->business_account_id]);
        DB::table('orders')->where('id', $first->id)->update(['cart_id' => $cart->id, 'status' => 'cancelled', 'cancelled_at' => now()]);

        $second = Order::factory()->create(['business_account_id' => $first->business_account_id, 'cart_id' => $cart->id]);

        expect($second->exists)->toBeTrue();
    });
});

describe('the figures', function () {
    it('refuses totals that do not add up, and negative amounts', function (array $figures, string $constraint) {
        expect(fn () => Order::factory()->create($figures))->toThrow(QueryException::class, $constraint);
    })->with([
        'a total that is not the sum' => [['subtotal' => Money::fromDecimal('1000.00'), 'total' => Money::fromDecimal('999.99')], 'orders_total_adds_up'],
        'a discount above the subtotal' => [['subtotal' => Money::fromDecimal('1000.00'), 'discount' => Money::fromDecimal('1000.01'), 'total' => Money::zero()], 'orders_discount_within_subtotal'],
        'a negative delivery charge' => [['delivery' => Money::fromDecimal('-0.01'), 'total' => Money::fromDecimal('999.99')], 'orders_amounts_not_negative'],
    ]);

    it('fixes what was bought, for how much and where it goes, once the order is placed', function (array $change) {
        $order = Order::factory()->create();

        expect(fn () => DB::table('orders')->where('id', $order->id)->update($change))
            ->toThrow(QueryException::class, 'cannot be changed once written');
    })->with([
        'the total' => [['subtotal' => '0.01', 'total' => '0.01']],
        'the shipping address' => [['shipping_address' => json_encode(['city' => 'Elsewhere'])]],
        'the payment' => [['payment_id' => null]],
        'the source' => [['source' => 'website', 'payment_id' => null]],
        'the reference' => [['reference' => 'ORD-REWRITTEN']],
        'the account' => [['business_account_id' => 999999]],
    ]);
});

describe('the status', function () {
    it('moves only along the transition map, in the database as well', function () {
        $order = Order::factory()->create();

        // Inside its own savepoint, so the refusal does not abort what follows.
        expect(fn () => DB::transaction(fn () => DB::table('orders')->where('id', $order->id)->update(['status' => 'delivered'])))
            ->toThrow(QueryException::class, 'cannot move from payment_pending to delivered');

        DB::table('orders')->where('id', $order->id)->update(['status' => 'paid', 'paid_at' => now()]);

        expect($order->refresh()->status)->toBe(OrderStatus::Paid);
    });

    it('refuses a status that does not exist', function () {
        expect(fn () => Order::factory()->create(['status' => 'teleported']))->toThrow(ValueError::class);

        expect(fn () => DB::table('orders')->insert([
            ...collect(DB::table('orders')->where('id', Order::factory()->create()->id)->first())->except(['id', 'public_id', 'reference', 'payment_id', 'idempotency_key'])->all(),
            'public_id' => (string) Str::ulid(),
            'reference' => 'ORD-FAKE-'.Str::upper(Str::random(6)),
            'source' => 'manual',
            'status' => 'teleported',
        ]))->toThrow(QueryException::class, 'orders_status_foreign');
    });

    it('records when an order was cancelled and why it was held', function (array $change, string $constraint) {
        $order = Order::factory()->create();

        expect(fn () => DB::table('orders')->where('id', $order->id)->update($change))
            ->toThrow(QueryException::class, $constraint);
    })->with([
        'cancelled with no time' => [['status' => 'cancelled'], 'orders_cancellation_is_recorded'],
        'held with no reason' => [['status' => 'on_hold', 'held_at' => now()], 'orders_hold_is_recorded'],
    ]);

    it('never deletes an order', function () {
        $order = Order::factory()->create();

        expect(fn () => $order->delete())->toThrow(LogicException::class)
            ->and(fn () => DB::table('orders')->where('id', $order->id)->delete())->toThrow(QueryException::class, 'cannot be deleted');
    });
});

describe('order lines', function () {
    it('keeps each line a snapshot whose figures add up', function () {
        $order = Order::factory()->create();
        $product = orderSchemaProduct();

        DB::table('order_items')->insert(orderSchemaLine($order, $product));

        $line = $order->items()->sole();

        expect($line->quantity)->toBe(10)
            ->and($line->line_total->toDecimal())->toBe('1092.50')
            ->and(fn () => $line->forceFill(['quantity' => 11])->save())->toThrow(LogicException::class)
            // Each refusal inside its own savepoint, so one does not abort the next.
            ->and(fn () => DB::transaction(fn () => DB::table('order_items')->where('id', $line->id)->update(['unit_price' => '0.01'])))->toThrow(QueryException::class, 'snapshot')
            ->and(fn () => DB::transaction(fn () => DB::table('order_items')->where('id', $line->id)->delete()))->toThrow(QueryException::class, 'snapshot');
    });

    it('refuses a line whose figures or shape are wrong', function (Closure $overrides, string $constraint) {
        $order = Order::factory()->create();
        $product = orderSchemaProduct();

        expect(fn () => DB::table('order_items')->insert(orderSchemaLine($order, $product, $overrides($product))))
            ->toThrow(QueryException::class, $constraint);
    })->with([
        'no units' => [fn () => ['quantity' => 0, 'line_subtotal' => '0.00', 'discount' => '0.00', 'tax' => '0.00', 'line_total' => '0.00'], 'order_items_quantity_positive'],
        'a subtotal that is not quantity times price' => [fn () => ['line_subtotal' => '1000.01'], 'order_items_subtotal_adds_up'],
        'a total that is not the sum' => [fn () => ['line_total' => '1092.51'], 'order_items_total_adds_up'],
        'a variation of another product' => [function () {
            $other = orderSchemaProduct();
            $variant = ProductVariant::create(['product_id' => $other->id, 'sku' => $other->sku.'-M', 'combination_key' => 'm', 'is_active' => true]);

            return ['product_variant_id' => $variant->id];
        }, 'must belong to its product'],
    ]);

    it('refuses the same line number twice on one order', function () {
        $order = Order::factory()->create();
        $product = orderSchemaProduct();

        DB::table('order_items')->insert(orderSchemaLine($order, $product));

        expect(fn () => DB::table('order_items')->insert(orderSchemaLine($order, $product)))
            ->toThrow(QueryException::class, 'order_items_order_id_line_number_unique');
    });
});
