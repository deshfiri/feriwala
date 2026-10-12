<?php

use App\Domain\ExternalStore\Actions\ImportExternalOrders;
use App\Domain\ExternalStore\Enums\ExternalOrderEventType;
use App\Domain\ExternalStore\Enums\ExternalStoreStatus;
use App\Domain\ExternalStore\Enums\ExternalWebhookState;
use App\Domain\ExternalStore\Jobs\SyncExternalStore;
use App\Domain\ExternalStore\Models\ExternalOrder;
use App\Domain\ExternalStore\Models\ExternalOrderEvent;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Domain\ExternalStore\Models\ExternalStoreWebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * A store telling Feriwala about its orders (D29): signed, recognised when it
 * repeats itself, and applied through the same path a sync uses.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->store = externalStoreConnected(externalStoreAccount());
});

/**
 * @param  array<string, mixed>  $payload
 */
function externalStoreWebhookSend(ExternalStore $store, array $payload, ?string $eventId = 'evt-1', ?string $secret = 'webhook-secret'): TestResponse
{
    $body = (string) json_encode($payload);

    return test()->call('POST', route('webhooks.stores', $store->public_id), server: array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_SIGNATURE' => $secret === null ? null : externalStoreSignature($body, $secret),
        'HTTP_X_WEBHOOK_EVENT_ID' => $eventId,
    ]), content: $body);
}

it('applies a signed order notification', function () {
    externalStoreWebhookSend($this->store, ['event' => 'order.placed', 'data' => externalStoreOrderRow()])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect(ExternalOrder::query()->sole()->order_number)->toBe('DF-2026-001234');

    $record = ExternalStoreWebhookEvent::query()->sole();
    expect($record->state)->toBe(ExternalWebhookState::Processed)
        // Applied, so the customer's details are not kept in the log.
        ->and($record->payload)->toBe(['cleared' => true])
        ->and(ExternalOrderEvent::query()->sole()->external_event_id)->toBe('evt-1');
});

it('refuses a notification with a wrong signature, and writes nothing', function () {
    externalStoreWebhookSend($this->store, ['event' => 'order.placed', 'data' => externalStoreOrderRow()], secret: 'not-the-secret')
        ->assertUnauthorized();

    expect(ExternalStoreWebhookEvent::query()->count())->toBe(0)
        ->and(ExternalOrder::query()->count())->toBe(0);
});

it('refuses an unsigned notification', function () {
    externalStoreWebhookSend($this->store, ['event' => 'order.placed', 'data' => externalStoreOrderRow()], secret: null)
        ->assertUnauthorized();
});

it('refuses a notification without an event id rather than making one up', function () {
    externalStoreWebhookSend($this->store, ['event' => 'order.placed', 'data' => externalStoreOrderRow()], eventId: null)
        ->assertUnprocessable();

    expect(ExternalStoreWebhookEvent::query()->count())->toBe(0);
});

it('recognises a replayed event and does not apply it again', function () {
    $payload = ['event' => 'order.updated', 'data' => externalStoreOrderRow()];

    externalStoreWebhookSend($this->store, $payload)->assertOk();
    externalStoreWebhookSend($this->store, $payload)->assertOk()->assertJson(['duplicate' => true]);

    $record = ExternalStoreWebhookEvent::query()->sole();
    expect($record->duplicate_count)->toBe(1)
        ->and($record->attempts)->toBe(1)
        ->and(ExternalOrder::query()->count())->toBe(1);
});

it('applies a failed event again when the store delivers it again', function () {
    // Unreadable the first time: a total with more precision than Taka has.
    $bad = ['event' => 'order.placed', 'data' => externalStoreOrderRow(['totals' => ['grand' => '960.005']])];
    externalStoreWebhookSend($this->store, $bad)->assertOk();

    $record = ExternalStoreWebhookEvent::query()->sole();
    expect($record->state)->toBe(ExternalWebhookState::Failed)
        ->and($record->error)->not->toBeNull();

    // The store's retry carries the same id; the stored payload is still there.
    externalStoreWebhookSend($this->store, $bad)->assertOk();

    expect($record->refresh()->attempts)->toBe(2);
});

it('answers a disconnected store the same as an unknown one', function () {
    $this->store->forceFill(['api_key' => null, 'api_secret' => null, 'webhook_secret' => null, 'disconnected_at' => now()]);
    $this->store->status = ExternalStoreStatus::Disconnected;
    $this->store->save();

    externalStoreWebhookSend($this->store, ['event' => 'order.placed', 'data' => externalStoreOrderRow()])->assertNotFound();
    $this->postJson(route('webhooks.stores', '01UNKNOWNSTORE00000000000000'), [])->assertNotFound();
});

it('records a payment the store received, on the order it belongs to', function () {
    externalStoreRecordOrder($this->store);

    externalStoreWebhookSend($this->store, [
        'event' => 'payment.received',
        'data' => ['order_number' => 'DF-2026-001234', 'amount' => 960.5, 'method' => 'bkash', 'received_at' => '2026-10-02T11:00:00+06:00'],
    ], eventId: 'pay-1')->assertOk();

    $order = ExternalOrder::query()->sole();
    $event = ExternalOrderEvent::query()->where('type', ExternalOrderEventType::PaymentReceived->value)->sole();

    expect($order->payment_status)->toBe('paid')
        ->and($order->paid_total?->toDecimal())->toBe('960.50')
        ->and($event->description)->toBe('bkash')
        ->and($event->external_event_id)->toBe('pay-1');
});

it('acknowledges an event it does not use without changing anything', function () {
    externalStoreWebhookSend($this->store, ['event' => 'inventory.low_stock', 'data' => ['sku' => 'X', 'quantity' => 1]])->assertOk();

    expect(ExternalStoreWebhookEvent::query()->sole()->state)->toBe(ExternalWebhookState::Ignored)
        ->and(ExternalOrder::query()->count())->toBe(0);
});

/**
 * An order row with its money flat, under the names a DFCOMMERCE webhook uses,
 * instead of nested under `totals`.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function externalStoreWebhookFlatRow(array $overrides = []): array
{
    $row = externalStoreOrderRow();
    unset($row['totals']);

    return [
        ...$row,
        'subtotal' => 1000,
        'discount_total' => 100,
        'coupon_code' => 'SAVE10',
        'shipping_total' => 60,
        'tax' => 0,
        'grand_total' => 960,
        'paid_total' => 0,
        ...$overrides,
    ];
}

describe('payload formats', function () {
    it('reads the money the same whether it is nested or flat', function (string $format) {
        $row = $format === 'flat' ? externalStoreWebhookFlatRow() : externalStoreOrderRow();

        externalStoreWebhookSend($this->store, ['event' => 'order.placed', 'data' => $row])->assertOk();

        $order = ExternalOrder::query()->sole();

        expect(ExternalStoreWebhookEvent::query()->sole()->state)->toBe(ExternalWebhookState::Processed)
            ->and($order->subtotal->toDecimal())->toBe('1000.00')
            ->and($order->discount->toDecimal())->toBe('100.00')
            ->and($order->coupon_code)->toBe('SAVE10')
            ->and($order->shipping_total->toDecimal())->toBe('60.00')
            ->and($order->tax->toDecimal())->toBe('0.00')
            ->and($order->total->toDecimal())->toBe('960.00')
            ->and($order->paid_total?->toDecimal())->toBe('0.00')
            // A top-level `shipping` is the address, never the shipping amount.
            ->and($order->shipping_address['area'] ?? null)->toBe('Banani');
    })->with(['nested', 'flat']);

    it('applies a flat discount_total from an order update', function () {
        externalStoreRecordOrder($this->store);

        externalStoreWebhookSend($this->store, ['event' => 'order.updated', 'data' => [
            'id' => 12345,
            'order_number' => 'DF-2026-001234',
            'discount_total' => '150.50',
            'grand_total' => '909.50',
            'updated_at' => '2026-10-02T09:00:00+06:00',
        ]], eventId: 'evt-2')->assertOk();

        $order = ExternalOrder::query()->sole();

        expect($order->discount->toDecimal())->toBe('150.50')
            ->and($order->total->toDecimal())->toBe('909.50')
            ->and($order->subtotal->toDecimal())->toBe('1000.00')
            ->and($order->shipping_total->toDecimal())->toBe('60.00');
    });

    it('keeps every amount and the status an update did not send, and never writes a zero for one', function () {
        externalStoreRecordOrder($this->store);

        externalStoreWebhookSend($this->store, ['event' => 'order.updated', 'data' => [
            'id' => 12345,
            'order_number' => 'DF-2026-001234',
            'payment_status' => 'paid',
            'updated_at' => '2026-10-02T09:00:00+06:00',
        ]], eventId: 'evt-2')->assertOk();

        $order = ExternalOrder::query()->with('items')->sole();

        expect($order->payment_status)->toBe('paid')
            ->and($order->source_status)->toBe('confirmed')
            ->and($order->subtotal->toDecimal())->toBe('1000.00')
            ->and($order->discount->toDecimal())->toBe('100.00')
            ->and($order->shipping_total->toDecimal())->toBe('60.00')
            ->and($order->total->toDecimal())->toBe('960.00')
            ->and($order->items)->toHaveCount(1)
            ->and($order->placed_at?->equalTo('2026-10-01T10:00:00+06:00'))->toBeTrue();
    });

    it('refuses an amount it cannot read rather than treating it as absent', function () {
        externalStoreRecordOrder($this->store);

        externalStoreWebhookSend($this->store, ['event' => 'order.updated', 'data' => [
            'id' => 12345,
            'order_number' => 'DF-2026-001234',
            'discount_total' => 'ten taka',
            'updated_at' => '2026-10-02T09:00:00+06:00',
        ]], eventId: 'evt-2')->assertOk();

        expect(ExternalStoreWebhookEvent::query()->where('event_id', 'evt-2')->sole()->state)->toBe(ExternalWebhookState::Failed)
            ->and(ExternalOrder::query()->sole()->discount->toDecimal())->toBe('100.00');
    });
});

describe('which order a notification is about', function () {
    it('updates a known order by its order number when the store sent no id', function () {
        externalStoreRecordOrder($this->store);

        externalStoreWebhookSend($this->store, ['event' => 'order.updated', 'data' => [
            'order_number' => 'DF-2026-001234',
            'status' => 'shipped',
            'updated_at' => '2026-10-02T09:00:00+06:00',
        ]], eventId: 'evt-2')->assertOk();

        $order = ExternalOrder::query()->sole();

        expect($order->source_status)->toBe('shipped')
            ->and($order->external_id)->toBe('12345')
            ->and($order->total->toDecimal())->toBe('960.00')
            ->and(ExternalStoreWebhookEvent::query()->where('event_id', 'evt-2')->sole()->state)->toBe(ExternalWebhookState::Processed);
    });

    it('never matches an order number across stores', function () {
        $other = externalStoreConnected(externalStoreAccount(), 'other.example.com');
        $theirs = externalStoreRecordOrder($other);
        Queue::fake([SyncExternalStore::class]);

        externalStoreWebhookSend($this->store, ['event' => 'order.updated', 'data' => [
            'order_number' => 'DF-2026-001234',
            'status' => 'cancelled',
            'updated_at' => '2026-10-02T09:00:00+06:00',
        ]])->assertOk();

        expect($theirs->refresh()->source_status)->toBe('confirmed')
            ->and(ExternalOrder::query()->where('external_store_id', $this->store->id)->count())->toBe(0)
            ->and(ExternalStoreWebhookEvent::query()->sole()->state)->toBe(ExternalWebhookState::Deferred);
    });

    it('does not roll an order back from an older notification without an id', function () {
        externalStoreRecordOrder($this->store, ['status' => 'delivered', 'updated_at' => '2026-10-05T09:00:00+06:00']);

        externalStoreWebhookSend($this->store, ['event' => 'order.updated', 'data' => [
            'order_number' => 'DF-2026-001234',
            'status' => 'confirmed',
            'updated_at' => '2026-10-02T09:00:00+06:00',
        ]], eventId: 'evt-2')->assertOk();

        expect(ExternalOrder::query()->sole()->source_status)->toBe('delivered');
    });

    it('refuses an id and an order number that belong to different orders, and merges nothing', function () {
        externalStoreRecordOrder($this->store);
        externalStoreRecordOrder($this->store, ['id' => 12346, 'order_number' => 'DF-2026-001235']);

        externalStoreWebhookSend($this->store, ['event' => 'order.updated', 'data' => externalStoreOrderRow([
            'id' => 12346,
            'status' => 'cancelled',
            'updated_at' => '2026-10-02T09:00:00+06:00',
        ])], eventId: 'evt-2')->assertOk();

        $record = ExternalStoreWebhookEvent::query()->where('event_id', 'evt-2')->sole();

        expect($record->state)->toBe(ExternalWebhookState::Failed)
            ->and($record->error)->toBe(__('stores.errors.webhook_identity_conflict'))
            ->and(ExternalOrder::query()->orderBy('order_number')->pluck('source_status', 'order_number')->all())
            ->toBe(['DF-2026-001234' => 'confirmed', 'DF-2026-001235' => 'confirmed']);
    });

    it('refuses an id the store could not have sent, rather than matching by number', function () {
        externalStoreRecordOrder($this->store);

        externalStoreWebhookSend($this->store, ['event' => 'order.updated', 'data' => [
            'id' => 'abc',
            'order_number' => 'DF-2026-001234',
            'status' => 'cancelled',
        ]], eventId: 'evt-2')->assertOk();

        expect(ExternalStoreWebhookEvent::query()->where('event_id', 'evt-2')->sole()->state)->toBe(ExternalWebhookState::Failed)
            ->and(ExternalOrder::query()->sole()->source_status)->toBe('confirmed');
    });
});

describe('a notification too thin to create its order', function () {
    it('keeps it, creates nothing and asks the pull API for the order', function (array $row) {
        Queue::fake([SyncExternalStore::class]);

        externalStoreWebhookSend($this->store, ['event' => 'order.updated', 'data' => $row])->assertOk();

        $record = ExternalStoreWebhookEvent::query()->sole();

        expect($record->state)->toBe(ExternalWebhookState::Deferred)
            ->and($record->payload['data']['order_number'])->toBe('DF-2026-001234')
            ->and($record->error)->toContain('missing')
            ->and(ExternalOrder::query()->count())->toBe(0);

        Queue::assertPushed(SyncExternalStore::class, fn (SyncExternalStore $job) => $job->storeId === $this->store->id);
    })->with([
        'no id' => fn () => array_diff_key(externalStoreOrderRow(), ['id' => true]),
        'no amounts' => fn () => array_diff_key(externalStoreOrderRow(), ['totals' => true]),
        'no items' => fn () => array_diff_key(externalStoreOrderRow(), ['items' => true]),
        'only a status' => fn () => ['id' => 12345, 'order_number' => 'DF-2026-001234', 'status' => 'shipped'],
    ]);

    it('closes it without applying it when the store delivers it again after the order arrived', function () {
        Queue::fake([SyncExternalStore::class]);
        // No `updated_at`: applied late, it could not be told from a newer copy.
        $thin = ['event' => 'order.updated', 'data' => [
            'order_number' => 'DF-2026-001234',
            'status' => 'confirmed',
        ]];

        externalStoreWebhookSend($this->store, $thin)->assertOk();

        // Meanwhile the pull brought the order in, already further along.
        externalStoreRecordOrder($this->store, ['status' => 'shipped']);

        externalStoreWebhookSend($this->store, $thin)->assertOk()->assertJson(['duplicate' => true]);

        $record = ExternalStoreWebhookEvent::query()->sole();

        expect($record->state)->toBe(ExternalWebhookState::Processed)
            ->and($record->payload)->toBe(['cleared' => true])
            ->and(ExternalOrder::query()->sole()->source_status)->toBe('shipped');
    });

    it('is closed by the next successful sync that brings its order in', function () {
        Queue::fake([SyncExternalStore::class]);
        externalStoreFakeDns();

        externalStoreWebhookSend($this->store, ['event' => 'order.updated', 'data' => [
            'order_number' => 'DF-2026-001234', 'status' => 'shipped',
        ]], eventId: 'evt-thin')->assertOk();
        externalStoreWebhookSend($this->store, ['event' => 'order.updated', 'data' => [
            'order_number' => 'DF-2026-009999', 'status' => 'shipped',
        ]], eventId: 'evt-other')->assertOk();

        // The store's pull API has the first order, complete; not the second.
        Http::fake(['https://shop.example.com/api/v1/orders*' => externalStoreOrdersPage([
            externalStoreOrderRow(['status' => 'shipped']),
        ])]);

        app(ImportExternalOrders::class)->handle($this->store);

        $closed = ExternalStoreWebhookEvent::query()->where('event_id', 'evt-thin')->sole();
        $waiting = ExternalStoreWebhookEvent::query()->where('event_id', 'evt-other')->sole();

        expect($closed->state)->toBe(ExternalWebhookState::Processed)
            ->and($closed->error)->toBeNull()
            ->and($closed->payload)->toBe(['cleared' => true])
            ->and($waiting->state)->toBe(ExternalWebhookState::Deferred)
            ->and($waiting->payload['data']['order_number'])->toBe('DF-2026-009999')
            ->and(ExternalOrder::query()->sole()->source_status)->toBe('shipped');
    });
});
