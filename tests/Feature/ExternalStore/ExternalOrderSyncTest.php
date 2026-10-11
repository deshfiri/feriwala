<?php

use App\Domain\ExternalStore\Actions\ImportExternalOrders;
use App\Domain\ExternalStore\Enums\ExternalOrderEventType;
use App\Domain\ExternalStore\Enums\ExternalStoreStatus;
use App\Domain\ExternalStore\Models\ExternalCustomer;
use App\Domain\ExternalStore\Models\ExternalOrder;
use App\Domain\ExternalStore\Models\ExternalOrderEvent;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Integrations\Store\Exceptions\StorePlatformException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Bringing a store's orders in (D29): once, exactly, with what changed
 * recorded and nothing Feriwala does with its own orders touched.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    externalStoreFakeDns();
    $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));

    $this->account = externalStoreAccount();
    $this->store = externalStoreConnected($this->account, status: ExternalStoreStatus::Pending);
});

/**
 * Answer the new-orders pass with `$rows` and the changed-orders pass with
 * `$changed`.
 *
 * @param  list<array<string, mixed>>  $rows
 * @param  list<array<string, mixed>>  $changed
 */
function externalOrderSyncStore(array $rows, array $changed = []): void
{
    Http::fake(function (HttpRequest $request) use ($rows, $changed) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return isset($query['updated_since'])
            ? externalStoreOrdersPage($changed)
            : externalStoreOrdersPage((int) ($query['since_id'] ?? 0) === 0 || $rows === [] ? $rows : []);
    });
}

function externalOrderSyncRun(ExternalStore $store): ?array
{
    return app(ImportExternalOrders::class)->handle($store);
}

describe('importing orders', function () {
    it('imports an order exactly as the store reported it', function () {
        externalOrderSyncStore([externalStoreOrderRow()]);

        expect(externalOrderSyncRun($this->store))->toBe(['created' => 1, 'updated' => 0, 'unreadable' => 0]);

        $order = ExternalOrder::query()->with('items')->sole();

        expect($order->business_account_id)->toBe($this->account->id)
            ->and($order->external_id)->toBe('12345')
            ->and($order->order_number)->toBe('DF-2026-001234')
            ->and($order->source_status)->toBe('confirmed')
            ->and($order->payment_status)->toBe('unpaid')
            ->and($order->subtotal->toDecimal())->toBe('1000.00')
            ->and($order->discount->toDecimal())->toBe('100.00')
            ->and($order->shipping_total->toDecimal())->toBe('60.00')
            ->and($order->total->toDecimal())->toBe('960.00')
            ->and($order->coupon_code)->toBe('SAVE10')
            ->and($order->shipping_address['area'])->toBe('Banani')
            ->and($order->items)->toHaveCount(1)
            ->and($order->items[0]->sku)->toBe('DF-A1B2C3')
            ->and($order->items[0]->quantity)->toBe(2)
            ->and($order->items[0]->unit_price->toDecimal())->toBe('500.00');

        $customer = ExternalCustomer::query()->sole();
        expect($customer->identity_key)->toBe('id:77')
            ->and($order->external_customer_id)->toBe($customer->id);
    });

    it('records the state an order arrived in, dated when it was placed, and invents nothing before it', function () {
        externalOrderSyncStore([externalStoreOrderRow(['status' => 'shipped'])]);

        externalOrderSyncRun($this->store);

        $event = ExternalOrderEvent::query()->sole();

        expect($event->type)->toBe(ExternalOrderEventType::Imported)
            ->and($event->previous_value)->toBeNull()
            ->and($event->new_value)->toBe('shipped')
            ->and($event->occurred_at?->toIso8601String())->toBe('2026-10-01T10:00:00+06:00');
    });

    it('keeps money exact to the poisha, through no float', function () {
        externalOrderSyncStore([externalStoreOrderRow(['totals' => ['subtotal' => 1000.1, 'grand' => 960.1, 'discount' => 100, 'shipping' => 60]])]);

        externalOrderSyncRun($this->store);

        expect(ExternalOrder::query()->sole()->total->toDecimal())->toBe('960.10');
    });

    it('skips an order it cannot represent faithfully, and says so', function () {
        externalOrderSyncStore([
            externalStoreOrderRow(['totals' => ['grand' => '960.005']]),
            externalStoreOrderRow(['id' => 12346, 'order_number' => 'DF-2026-001235']),
        ]);

        $tally = externalOrderSyncRun($this->store);

        expect($tally)->toBe(['created' => 1, 'updated' => 0, 'unreadable' => 1])
            ->and(ExternalOrder::query()->pluck('order_number')->all())->toBe(['DF-2026-001235'])
            ->and($this->store->refresh()->last_error)->toBe(__('stores.errors.some_unreadable', ['count' => 1]));
    });

    it('never writes the same order twice, however often it is synced', function () {
        externalOrderSyncStore([externalStoreOrderRow()], [externalStoreOrderRow()]);

        externalOrderSyncRun($this->store);
        externalOrderSyncRun($this->store);
        externalOrderSyncRun($this->store);

        expect(ExternalOrder::query()->count())->toBe(1)
            ->and(ExternalOrderEvent::query()->count())->toBe(1);
    });

    it('applies what the store changed and records each change', function () {
        externalStoreRecordOrder($this->store);
        externalOrderSyncStore([], [externalStoreOrderRow([
            'status' => 'delivered',
            'payment_status' => 'paid',
            'updated_at' => '2026-10-03T09:00:00+06:00',
        ])]);

        expect(externalOrderSyncRun($this->store))->toBe(['created' => 0, 'updated' => 1, 'unreadable' => 0]);

        $order = ExternalOrder::query()->sole();
        $changes = ExternalOrderEvent::query()->where('type', '!=', ExternalOrderEventType::Imported->value)->orderBy('id')->get();

        expect($order->source_status)->toBe('delivered')
            ->and($order->payment_status)->toBe('paid')
            ->and($changes)->toHaveCount(2)
            ->and($changes[0]->type)->toBe(ExternalOrderEventType::StatusChanged)
            ->and($changes[0]->previous_value)->toBe('confirmed')
            ->and($changes[0]->new_value)->toBe('delivered')
            ->and($changes[1]->type)->toBe(ExternalOrderEventType::PaymentStatusChanged);
    });

    it('keeps details a partial update did not send', function () {
        externalStoreRecordOrder($this->store);

        $partial = externalStoreOrderRow(['status' => 'shipped', 'updated_at' => '2026-10-03T09:00:00+06:00']);
        unset($partial['shipping'], $partial['note'], $partial['customer']['email']);
        externalOrderSyncStore([], [$partial]);

        externalOrderSyncRun($this->store);

        $order = ExternalOrder::query()->sole();

        expect($order->source_status)->toBe('shipped')
            ->and($order->shipping_address['area'] ?? null)->toBe('Banani')
            ->and($order->note)->toBe('Call before delivery')
            ->and($order->customer['email'])->toBe('khalid@example.test');
    });

    it('never rolls an order back to an older copy', function () {
        externalStoreRecordOrder($this->store, ['status' => 'delivered', 'updated_at' => '2026-10-05T09:00:00+06:00']);
        externalOrderSyncStore([], [externalStoreOrderRow(['status' => 'confirmed', 'updated_at' => '2026-10-02T09:00:00+06:00'])]);

        externalOrderSyncRun($this->store);

        expect(ExternalOrder::query()->sole()->source_status)->toBe('delivered');
    });

    it('creates no Feriwala order, payment, wallet or ledger entry', function () {
        $before = [
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'ledger_entries' => DB::table('ledger_entries')->count(),
            'wallet_transactions' => DB::table('wallet_transactions')->count(),
        ];

        externalOrderSyncStore([externalStoreOrderRow(['payment_status' => 'paid', 'totals' => ['paid' => 960]])]);
        externalOrderSyncRun($this->store);

        expect([
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'ledger_entries' => DB::table('ledger_entries')->count(),
            'wallet_transactions' => DB::table('wallet_transactions')->count(),
        ])->toBe($before);
    });
});

describe('cursors and recovery', function () {
    it('moves the new-orders cursor past what it read, and the change window after a full pass', function () {
        externalOrderSyncStore([externalStoreOrderRow(['id' => 900])]);

        externalOrderSyncRun($this->store);

        $this->store->refresh();
        expect($this->store->order_cursor)->toBe(900)
            ->and($this->store->orders_updated_through?->toIso8601String())->toBe(now()->toIso8601String());
    });

    it('stops when the store hands back a cursor that does not move forward', function () {
        Http::fake(fn () => externalStoreOrdersPage([externalStoreOrderRow()], 5));
        $this->store->forceFill(['order_cursor' => 5])->save();

        externalOrderSyncRun($this->store);

        // New orders: one page, whose cursor does not pass 5. Changed orders:
        // from 0 to 5, then a page whose cursor does not move. Bounded, not an
        // endless walk.
        Http::assertSentCount(3);
    });

    it('becomes connected after its first successful sync', function () {
        externalOrderSyncStore([]);

        externalOrderSyncRun($this->store);

        expect($this->store->refresh()->status)->toBe(ExternalStoreStatus::Connected)
            ->and($this->store->last_success_at)->not->toBeNull();
    });

    it('reads as failing after several failed syncs in a row, and recovers on the next success', function () {
        $storeIsDown = true;
        Http::fake(function () use (&$storeIsDown) {
            return $storeIsDown ? Http::response([], 503) : externalStoreOrdersPage([]);
        });

        foreach (range(1, 3) as $attempt) {
            try {
                externalOrderSyncRun($this->store);
            } catch (StorePlatformException) {
            }
        }

        $this->store->refresh();
        expect($this->store->status)->toBe(ExternalStoreStatus::Failing)
            ->and($this->store->consecutive_failures)->toBe(3)
            ->and($this->store->last_error)->toBe(__('stores.errors.store_error', ['status' => 503]));

        $storeIsDown = false;
        externalOrderSyncRun($this->store);

        $this->store->refresh();
        expect($this->store->status)->toBe(ExternalStoreStatus::Connected)
            ->and($this->store->consecutive_failures)->toBe(0)
            ->and($this->store->last_error)->toBeNull();
    });

    it('marks a refused key as failing at once', function () {
        Http::fake(['https://shop.example.com/*' => Http::response([], 401)]);

        expect(fn () => externalOrderSyncRun($this->store))->toThrow(StorePlatformException::class);

        expect($this->store->refresh()->status)->toBe(ExternalStoreStatus::Failing);
    });

    it('does nothing for a disconnected store or one whose package lost the feature', function () {
        Http::fake();
        $unentitled = externalStoreConnected(externalStoreAccount(entitled: false), 'other.example.com');

        expect(externalOrderSyncRun($unentitled))->toBeNull();
        Http::assertNothingSent();
    });
});
