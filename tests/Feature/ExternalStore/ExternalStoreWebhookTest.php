<?php

use App\Domain\ExternalStore\Enums\ExternalOrderEventType;
use App\Domain\ExternalStore\Enums\ExternalStoreStatus;
use App\Domain\ExternalStore\Enums\ExternalWebhookState;
use App\Domain\ExternalStore\Models\ExternalOrder;
use App\Domain\ExternalStore\Models\ExternalOrderEvent;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Domain\ExternalStore\Models\ExternalStoreWebhookEvent;
use Illuminate\Support\Facades\Http;
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
