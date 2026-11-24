<?php

use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Data\RevisableOrderState;
use App\Domain\Order\Enums\OrderRevisionStatus;
use App\Domain\Order\Enums\RevisionAdjustmentKind;
use App\Domain\Order\Enums\RevisionCourierConsequence;
use App\Domain\Order\Enums\RevisionLineChange;
use App\Domain\Order\Enums\RevisionPaymentConsequence;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderRevision;
use App\Domain\Order\Models\OrderRevisionAdjustment;
use App\Domain\Order\Models\OrderRevisionLine;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The order revision schema (D38).
 *
 * An order's customer, addresses, money and lines change only through an
 * applied, append-only revision whose snapshots match the change exactly; its
 * identity never changes at all; a line is never edited, only superseded once
 * by a revision that names it.
 */

function revisionSchemaProduct(): Product
{
    return Product::create([
        'name' => 'Steel water bottle',
        'sku' => 'FW-WB-'.Str::upper(Str::random(6)),
        'category_id' => Category::create(['name' => 'Kitchen '.Str::random(6)])->id,
        'wholesale_price' => Money::fromDecimal('500.00'),
        'status' => ProductStatus::Active,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
    ]);
}

function revisionSchemaLine(Order $order, Product $product, int $lineNumber = 1, ?OrderRevision $introducedBy = null): OrderItem
{
    return OrderItem::create([
        'order_id' => $order->id,
        'line_number' => $lineNumber,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'product_name' => $product->name,
        'quantity' => 2,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('500.00'),
        'line_subtotal' => Money::fromDecimal('1000.00'),
        'discount' => Money::fromDecimal('0.00'),
        'tax' => Money::fromDecimal('0.00'),
        'line_total' => Money::fromDecimal('1000.00'),
        'introduced_by_revision_id' => $introducedBy?->id,
    ]);
}

/**
 * An applied revision row, written as the revision action will write it.
 *
 * @param  array<string, mixed>  $changes  revisable columns and their new values
 * @param  array<string, mixed>  $staleBefore  columns the before snapshot records wrongly
 */
function revisionSchemaRevision(Order $order, array $changes, ?int $number = null, array $staleBefore = []): OrderRevision
{
    $after = RevisableOrderState::with(RevisableOrderState::current($order), $changes);
    $before = RevisableOrderState::with(RevisableOrderState::current($order), $staleBefore);

    return OrderRevision::create([
        'order_id' => $order->id,
        'revision_number' => $number ?? $order->revision_number + 1,
        'status' => OrderRevisionStatus::Applied,
        'sections' => ['addresses'],
        'requested_by' => $order->placed_by,
        'applied_by' => $order->placed_by,
        'reason' => 'Customer gave the wrong flat number on the phone.',
        'before_snapshot' => ['order' => $before, 'lines' => []],
        'requested_snapshot' => ['changes' => array_keys($changes)],
        'final_snapshot' => ['order' => $after, 'lines' => []],
        'currency_code' => 'BDT',
        'total_before' => Money::fromDecimal((string) $before['total']),
        'total_after' => Money::fromDecimal((string) $after['total']),
        'total_difference' => Money::fromDecimal((string) $after['total'])->minus(Money::fromDecimal((string) $before['total'])),
        'stock_difference' => [],
        'payment_consequence' => RevisionPaymentConsequence::None,
        'courier_consequence' => RevisionCourierConsequence::NotBooked,
        'fingerprint' => hash('sha256', Str::random()),
        'idempotency_key' => 'order-revision:'.Str::ulid(),
        'requested_at' => now(),
        'applied_at' => now(),
    ]);
}

/**
 * A statement expected to be refused, in a savepoint of its own: a refused
 * statement aborts the surrounding test transaction, and the next expectation
 * would fail on that instead of on its own refusal.
 */
function revisionSchemaAttempt(Closure $statement): Closure
{
    return fn () => DB::transaction($statement);
}

/**
 * @return array<string, string|null>
 */
function revisionSchemaNewAddress(Order $order): array
{
    return [...$order->shipping_address, 'line_1' => 'House 12, Road 4, Flat 3B'];
}

describe('the order', function () {
    it('refuses a change to a revisable column with no revision, exactly as before', function () {
        $order = Order::factory()->create();

        expect(fn () => $order->update(['shipping_address' => revisionSchemaNewAddress($order)]))
            ->toThrow(QueryException::class, 'changes only through its next applied revision');
    });

    it('refuses the next revision number when no applied revision carries it', function () {
        $order = Order::factory()->create();

        expect(fn () => $order->update([
            'shipping_address' => revisionSchemaNewAddress($order),
            'revision_number' => 1,
        ]))->toThrow(QueryException::class, 'has no applied revision 1');
    });

    it('accepts exactly what an applied revision records, and moves the revision number', function () {
        $order = Order::factory()->create();
        $address = revisionSchemaNewAddress($order);
        revisionSchemaRevision($order, ['shipping_address' => $address]);

        $order->update(['shipping_address' => $address, 'revision_number' => 1]);

        expect($order->refresh()->shipping_address['line_1'])->toBe('House 12, Road 4, Flat 3B')
            ->and($order->revision_number)->toBe(1)
            ->and($order->revisions)->toHaveCount(1);
    });

    it('accepts a money correction that adds up and matches its revision', function () {
        $order = Order::factory()->create();
        revisionSchemaRevision($order, [
            'subtotal' => Money::fromDecimal('1200.00'),
            'total' => Money::fromDecimal('1200.00'),
        ]);

        $order->update([
            'subtotal' => Money::fromDecimal('1200.00'),
            'total' => Money::fromDecimal('1200.00'),
            'revision_number' => 1,
        ]);

        expect($order->refresh()->total->toDecimal())->toBe('1200.00');
    });

    it('refuses a change that differs from what the applied revision records', function () {
        $order = Order::factory()->create();
        revisionSchemaRevision($order, ['shipping_address' => revisionSchemaNewAddress($order)]);

        expect(fn () => $order->update([
            'shipping_address' => [...$order->shipping_address, 'line_1' => 'Somewhere else entirely'],
            'revision_number' => 1,
        ]))->toThrow(QueryException::class, 'shipping_address does not match applied revision 1');
    });

    it('refuses a column the revision did not record changing', function () {
        $order = Order::factory()->create();
        $address = revisionSchemaNewAddress($order);
        revisionSchemaRevision($order, ['shipping_address' => $address]);

        expect(fn () => $order->update([
            'shipping_address' => $address,
            'billing_address' => $address,
            'revision_number' => 1,
        ]))->toThrow(QueryException::class, 'billing_address does not match applied revision 1');
    });

    it('refuses a revision whose before snapshot is not what the order was', function () {
        $order = Order::factory()->create();
        $address = revisionSchemaNewAddress($order);

        // Recorded against a reading the order never had — what a stale
        // preview applied after someone else's revision would look like.
        revisionSchemaRevision($order, ['shipping_address' => $address], staleBefore: [
            'shipping_address' => [...$order->shipping_address, 'line_1' => 'An address it never had'],
        ]);

        expect(fn () => $order->update(['shipping_address' => $address, 'revision_number' => 1]))
            ->toThrow(QueryException::class, 'shipping_address does not match applied revision 1');
    });

    it('refuses skipping a revision number', function () {
        $order = Order::factory()->create();
        $address = revisionSchemaNewAddress($order);
        revisionSchemaRevision($order, ['shipping_address' => $address], number: 2);

        expect(fn () => $order->update(['shipping_address' => $address, 'revision_number' => 2]))
            ->toThrow(QueryException::class, 'changes only through its next applied revision');
    });

    it('never lets a revision change what the order is', function (string $column, mixed $value) {
        $order = Order::factory()->create();
        revisionSchemaRevision($order, ['shipping_address' => revisionSchemaNewAddress($order)]);

        expect(fn () => DB::table('orders')->where('id', $order->id)->update([$column => $value, 'revision_number' => 1]))
            ->toThrow(QueryException::class, "orders.{$column} cannot be changed once written");
    })->with([
        'its reference' => ['reference', 'ORD-REWRITTEN'],
        'its source' => ['source', 'manual'],
        'its placement time' => ['placed_at', '2020-01-01 00:00:00'],
        'its idempotency key' => ['idempotency_key', 'rewritten'],
    ]);

    it('never lets a revision move an order to another account', function () {
        $order = Order::factory()->create();
        $other = Order::factory()->create();

        expect(fn () => DB::table('orders')->where('id', $order->id)->update(['business_account_id' => $other->business_account_id]))
            ->toThrow(QueryException::class, 'orders.business_account_id cannot be changed once written');
    });

    it('still lets the workflow move the order without a revision', function () {
        $order = Order::factory()->create();

        DB::table('orders')->where('id', $order->id)->update(['customer_note' => 'Leave at the gate.']);

        expect($order->refresh()->customer_note)->toBe('Leave at the gate.');
    });
});

describe('the revision records', function () {
    it('refuses to change or remove an applied revision, its lines or its adjustments', function () {
        $order = Order::factory()->create();
        $product = revisionSchemaProduct();
        $line = revisionSchemaLine($order, $product);
        $revision = revisionSchemaRevision($order, ['shipping_address' => revisionSchemaNewAddress($order)]);

        $revisionLine = OrderRevisionLine::create([
            'order_revision_id' => $revision->id,
            'change' => RevisionLineChange::Removed,
            'previous_order_item_id' => $line->id,
            'sku' => $line->sku,
            'product_name' => $line->product_name,
            'quantity_before' => 2,
            'currency_code' => 'BDT',
        ]);

        $adjustment = OrderRevisionAdjustment::create([
            'order_revision_id' => $revision->id,
            'kind' => RevisionAdjustmentKind::StockReleased,
            'reference' => 'RES-1',
            'detail' => ['quantity' => 2],
        ]);

        foreach (['order_revisions' => $revision->id, 'order_revision_lines' => $revisionLine->id, 'order_revision_adjustments' => $adjustment->id] as $table => $id) {
            expect(revisionSchemaAttempt(fn () => DB::table($table)->where('id', $id)->update(['created_at' => now()->addDay()])))
                ->toThrow(QueryException::class, 'append-only')
                ->and(revisionSchemaAttempt(fn () => DB::table($table)->where('id', $id)->delete()))
                ->toThrow(QueryException::class, 'append-only');
        }

        expect(fn () => $revision->update(['reason' => 'Something else, after the fact.']))
            ->toThrow(LogicException::class);
    });

    it('requires a reason worth reading', function () {
        $order = Order::factory()->create();

        expect(fn () => DB::table('order_revisions')->insert([
            ...collect(revisionSchemaRevision($order, ['shipping_address' => revisionSchemaNewAddress($order)])->getAttributes())
                ->except(['id', 'public_id', 'idempotency_key', 'revision_number'])->all(),
            'public_id' => (string) Str::ulid(),
            'revision_number' => 2,
            'idempotency_key' => 'order-revision:'.Str::ulid(),
            'reason' => 'typo',
        ]))->toThrow(QueryException::class, 'order_revisions_reason_given');
    });

    it('refuses a difference that does not add up', function () {
        $order = Order::factory()->create();
        $revision = revisionSchemaRevision($order, ['shipping_address' => revisionSchemaNewAddress($order)]);

        expect(fn () => DB::table('order_revisions')->insert([
            ...collect($revision->getAttributes())->except(['id', 'public_id', 'idempotency_key', 'revision_number'])->all(),
            'public_id' => (string) Str::ulid(),
            'revision_number' => 2,
            'idempotency_key' => 'order-revision:'.Str::ulid(),
            'total_difference' => '50.00',
        ]))->toThrow(QueryException::class, 'order_revisions_difference_adds_up');
    });

    it('applies one revision per number, and one per idempotency key', function () {
        $order = Order::factory()->create();
        $revision = revisionSchemaRevision($order, ['shipping_address' => revisionSchemaNewAddress($order)]);

        expect(revisionSchemaAttempt(fn () => revisionSchemaRevision($order, ['shipping_address' => revisionSchemaNewAddress($order)], number: 1)))
            ->toThrow(QueryException::class, 'order_revisions_order_id_revision_number_unique')
            ->and(revisionSchemaAttempt(fn () => DB::table('order_revisions')->insert([
                ...collect($revision->getAttributes())->except(['id', 'public_id', 'revision_number'])->all(),
                'public_id' => (string) Str::ulid(),
                'revision_number' => 2,
            ])))->toThrow(QueryException::class, 'order_revisions_idempotency_key_unique');
    });
});

describe('the order lines', function () {
    it('lets an applied revision that names a line supersede it, once', function () {
        $order = Order::factory()->create();
        $product = revisionSchemaProduct();
        $line = revisionSchemaLine($order, $product);
        $revision = revisionSchemaRevision($order, ['shipping_address' => revisionSchemaNewAddress($order)]);

        $replacement = revisionSchemaLine($order, $product, lineNumber: 2, introducedBy: $revision);

        OrderRevisionLine::create([
            'order_revision_id' => $revision->id,
            'change' => RevisionLineChange::QuantityChanged,
            'previous_order_item_id' => $line->id,
            'new_order_item_id' => $replacement->id,
            'sku' => $line->sku,
            'product_name' => $line->product_name,
            'quantity_before' => 2,
            'quantity_after' => 2,
            'currency_code' => 'BDT',
        ]);

        $line->update(['superseded_by_revision_id' => $revision->id]);

        expect($line->refresh()->superseded_by_revision_id)->toBe($revision->id)
            ->and($order->items()->pluck('id')->all())->toBe([$replacement->id])
            ->and($order->allItems()->pluck('id')->all())->toBe([$line->id, $replacement->id])
            ->and(revisionSchemaAttempt(fn () => DB::table('order_items')->where('id', $line->id)->update(['superseded_by_revision_id' => null])))
            ->toThrow(QueryException::class, 'changing superseded_by_revision_id is not permitted');
    });

    it('refuses to supersede a line its revision does not name', function () {
        $order = Order::factory()->create();
        $line = revisionSchemaLine($order, revisionSchemaProduct());
        $revision = revisionSchemaRevision($order, ['shipping_address' => revisionSchemaNewAddress($order)]);

        expect(fn () => DB::table('order_items')->where('id', $line->id)->update(['superseded_by_revision_id' => $revision->id]))
            ->toThrow(QueryException::class, 'changing superseded_by_revision_id is not permitted');
    });

    it('refuses to supersede a line through another order\'s revision', function () {
        $order = Order::factory()->create();
        $other = Order::factory()->create();
        $line = revisionSchemaLine($order, revisionSchemaProduct());
        $foreign = revisionSchemaRevision($other, ['shipping_address' => revisionSchemaNewAddress($other)]);

        expect(fn () => OrderRevisionLine::create([
            'order_revision_id' => $foreign->id,
            'change' => RevisionLineChange::Removed,
            'previous_order_item_id' => $line->id,
            'sku' => $line->sku,
            'product_name' => $line->product_name,
            'quantity_before' => 2,
            'currency_code' => 'BDT',
        ]))->toThrow(QueryException::class, 'effective line of the revision\'s own order');
    });

    it('refuses a revised line written into another order', function () {
        $order = Order::factory()->create();
        $other = Order::factory()->create();
        $foreign = revisionSchemaRevision($other, ['shipping_address' => revisionSchemaNewAddress($other)]);

        expect(fn () => revisionSchemaLine($order, revisionSchemaProduct(), introducedBy: $foreign))
            ->toThrow(QueryException::class, 'belongs to its own order\'s revision');
    });

    it('still refuses any other change to a line, and its removal', function () {
        $order = Order::factory()->create();
        $line = revisionSchemaLine($order, revisionSchemaProduct());

        expect(revisionSchemaAttempt(fn () => DB::table('order_items')->where('id', $line->id)->update(['quantity' => 3])))
            ->toThrow(QueryException::class, 'changing quantity is not permitted')
            ->and(revisionSchemaAttempt(fn () => DB::table('order_items')->where('id', $line->id)->delete()))
            ->toThrow(QueryException::class, 'order_items is a snapshot')
            ->and(fn () => $line->update(['quantity' => 3]))
            ->toThrow(LogicException::class);
    });
});
