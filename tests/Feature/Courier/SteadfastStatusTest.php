<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Courier\Actions\ApplySteadfastStatus;
use App\Domain\Courier\Actions\ConfigureSteadfast;
use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Actions\RecordManualCourierStatusUpdate;
use App\Domain\Courier\Actions\RotateSteadfastWebhookToken;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Enums\ShipmentStatusChangeSource;
use App\Domain\Courier\Events\SteadfastStatusNeedsReview;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Settings\SettingsRepository;
use App\Integrations\Courier\Steadfast\SteadfastStatus;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/SteadfastFixtures.php';

/*
 * What Steadfast tells Banij, and what Banij does about it. All HTTP is faked.
 * The vocabulary is the eleven statuses Steadfast's package documents; the
 * webhook payload is the five fields its README validates.
 */
const STEADFAST_STATUS_BASE = 'https://portal.steadfast.com.bd/api/v1';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function steadfastStatusPayload(Shipment $shipment, string $status, array $overrides = []): array
{
    return [
        'consignment_id' => $shipment->external_id,
        'invoice' => $shipment->order->reference,
        'status' => $status,
        'cod_amount' => 1000,
        'updated_at' => '2026-11-01 10:00:00',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function steadfastStatusPost(mixed $test, array $payload, ?string $authorization): TestResponse
{
    return $test->postJson(
        route('webhooks.courier.steadfast'),
        $payload,
        $authorization === null ? [] : ['Authorization' => $authorization],
    );
}

function steadfastStatusHistoryCount(Shipment $shipment): int
{
    return DB::table('shipment_status_history')->where('shipment_id', $shipment->id)->count();
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::CourierManager);
    steadfastFixtureEnable();
});

describe('the status vocabulary', function () {
    it('maps each documented value exactly as the table says', function (string $raw, ?OrderCourierStatus $target, bool $final, bool $review, bool $financial) {
        $status = SteadfastStatus::fromRaw($raw);

        expect($status)->not->toBeNull()
            ->and($status->courierTarget())->toBe($target)
            ->and($status->isProviderFinal())->toBe($final)
            ->and($status->requiresReview())->toBe($review)
            ->and($status->allowsFinancialEffect())->toBe($financial);
    })->with([
        'pending' => ['pending', null, false, false, false],
        'in_review' => ['in_review', null, false, false, false],
        'hold' => ['hold', null, false, true, false],
        'delivered_approval_pending' => ['delivered_approval_pending', null, false, false, false],
        'partial_delivered_approval_pending' => ['partial_delivered_approval_pending', null, false, true, false],
        'cancelled_approval_pending' => ['cancelled_approval_pending', null, false, false, false],
        'unknown_approval_pending' => ['unknown_approval_pending', null, false, true, false],
        'delivered' => ['delivered', OrderCourierStatus::Delivered, true, false, true],
        'partial_delivered' => ['partial_delivered', null, true, true, false],
        'cancelled' => ['cancelled', OrderCourierStatus::Cancelled, true, false, false],
        'unknown' => ['unknown', null, false, true, false],
    ]);

    it('covers exactly the eleven documented values', function () {
        expect(array_map(fn (SteadfastStatus $status) => $status->value, SteadfastStatus::cases()))->toEqualCanonicalizing([
            'pending', 'delivered_approval_pending', 'partial_delivered_approval_pending', 'cancelled_approval_pending',
            'unknown_approval_pending', 'delivered', 'partial_delivered', 'cancelled', 'hold', 'in_review', 'unknown',
        ]);
    });

    it('does not guess: only an exact value matches', function (?string $raw) {
        expect(SteadfastStatus::fromRaw($raw))->toBeNull();
    })->with([null, '', 'Delivered', 'DELIVERED', ' delivered', 'delivered ', 'out_for_delivery', 'returned', 'partially delivered']);

    it('lets only a delivered parcel reach the delivery pipeline', function () {
        $financial = array_filter(SteadfastStatus::cases(), fn (SteadfastStatus $status) => $status->allowsFinancialEffect());

        expect(array_values($financial))->toBe([SteadfastStatus::Delivered]);
    });
});

describe('applying a status', function () {
    it('walks a delivered parcel through the legal steps, through the shared action', function () {
        $shipment = steadfastFixtureShipment();

        $applied = app(ApplySteadfastStatus::class)->handle($shipment, 'delivered', ShipmentStatusChangeSource::Webhook);

        $shipment->refresh();

        expect($applied->changed)->toBeTrue()
            ->and($applied->courierMoved)->toBeTrue()
            ->and($shipment->status)->toBe(OrderCourierStatus::Delivered)
            ->and($shipment->provider_status)->toBe('delivered')
            ->and($shipment->needs_review)->toBeFalse()
            ->and($shipment->order->refresh()->courier_status)->toBe(OrderCourierStatus::Delivered)
            ->and($shipment->order->delivery_status)->toBe(OrderDeliveryStatus::Delivered);

        // Every step is on record as having come from the provider, never from a person.
        $sources = DB::table('shipment_status_history')->where('shipment_id', $shipment->id)->orderBy('id')->pluck('source')->all();

        expect(array_slice($sources, 1))->each->toBe('webhook');
    });

    it('is idempotent: the same status again changes nothing', function () {
        $shipment = steadfastFixtureShipment();

        app(ApplySteadfastStatus::class)->handle($shipment, 'delivered', ShipmentStatusChangeSource::Webhook);

        $history = steadfastStatusHistoryCount($shipment);
        $events = $shipment->trackingEvents()->count();

        $again = app(ApplySteadfastStatus::class)->handle($shipment->refresh(), 'delivered', ShipmentStatusChangeSource::System);

        expect($again->changed)->toBeFalse()
            ->and(steadfastStatusHistoryCount($shipment))->toBe($history)
            ->and($shipment->trackingEvents()->count())->toBe($events);
    });

    it('keeps a raw status that moves nothing, once', function () {
        $shipment = steadfastFixtureShipment();

        app(ApplySteadfastStatus::class)->handle($shipment, 'pending', ShipmentStatusChangeSource::System);
        app(ApplySteadfastStatus::class)->handle($shipment->refresh(), 'pending', ShipmentStatusChangeSource::System);

        $shipment->refresh();

        expect($shipment->provider_status)->toBe('pending')
            ->and($shipment->status)->toBe(OrderCourierStatus::Assigned)
            ->and($shipment->needs_review)->toBeFalse()
            ->and($shipment->trackingEvents()->where('event_code', 'steadfast.pending')->count())->toBe(1);
    });

    it('does not infer COD collection, settlement or profit from a delivery', function () {
        $shipment = steadfastFixtureShipment();

        app(ApplySteadfastStatus::class)->handle($shipment, 'delivered', ShipmentStatusChangeSource::Webhook);

        expect(DB::table('order_proceeds_settlements')->count())->toBe(0)
            ->and($shipment->refresh()->cod_amount->toDecimal())->toBe('1000.00');
    });

    it('applies a cancellation Steadfast reports, with a reason, while that is legal', function () {
        $shipment = steadfastFixtureShipment();

        app(ApplySteadfastStatus::class)->handle($shipment, 'cancelled', ShipmentStatusChangeSource::Webhook);

        expect($shipment->refresh()->status)->toBe(OrderCourierStatus::Cancelled)
            ->and($shipment->cancellation_reason)->toBe('Cancelled at Steadfast.');
    });

    it('flags, rather than forces, a cancellation for a parcel already on its way', function () {
        $shipment = steadfastFixtureShipment();

        foreach ([OrderCourierStatus::PickupRequested, OrderCourierStatus::PickedUp, OrderCourierStatus::InTransit] as $step) {
            app(RecordManualCourierStatusUpdate::class)->handle($shipment->refresh(), $step, $this->staff);
        }

        $applied = app(ApplySteadfastStatus::class)->handle($shipment->refresh(), 'cancelled', ShipmentStatusChangeSource::Webhook);

        $shipment->refresh();

        expect($applied->newlyFlagged)->toBeTrue()
            ->and($shipment->status)->toBe(OrderCourierStatus::InTransit)
            ->and($shipment->provider_status)->toBe('cancelled')
            ->and($shipment->needs_review)->toBeTrue()
            ->and($shipment->order->refresh()->courier_status)->toBe(OrderCourierStatus::InTransit);
    });

    it('never moves a finished parcel again', function () {
        $shipment = steadfastFixtureShipment();

        app(ApplySteadfastStatus::class)->handle($shipment, 'delivered', ShipmentStatusChangeSource::Webhook);
        $history = steadfastStatusHistoryCount($shipment);

        app(ApplySteadfastStatus::class)->handle($shipment->refresh(), 'cancelled', ShipmentStatusChangeSource::Webhook);

        expect($shipment->refresh()->status)->toBe(OrderCourierStatus::Delivered)
            ->and($shipment->needs_review)->toBeTrue()
            ->and(steadfastStatusHistoryCount($shipment))->toBe($history);
    });

    it('does not treat partial delivery as delivery', function (string $raw) {
        $shipment = steadfastFixtureShipment();

        app(ApplySteadfastStatus::class)->handle($shipment, $raw, ShipmentStatusChangeSource::Webhook);

        $shipment->refresh();

        expect($shipment->status)->toBe(OrderCourierStatus::Assigned)
            ->and($shipment->needs_review)->toBeTrue()
            ->and($shipment->order->refresh()->delivery_status)->toBe(OrderDeliveryStatus::CourierAssigned)
            ->and(DB::table('order_proceeds_settlements')->count())->toBe(0);
    })->with(['partial_delivered', 'partial_delivered_approval_pending']);

    it('waits for Steadfast to finish before acting on an approval step', function (string $raw) {
        $shipment = steadfastFixtureShipment();

        app(ApplySteadfastStatus::class)->handle($shipment, $raw, ShipmentStatusChangeSource::Webhook);

        expect($shipment->refresh()->status)->toBe(OrderCourierStatus::Assigned)
            ->and($shipment->provider_status)->toBe($raw);
    })->with(['delivered_approval_pending', 'cancelled_approval_pending']);

    it('keeps an unknown status as received, flags it, and tells staff once', function () {
        Event::fake([SteadfastStatusNeedsReview::class]);

        $shipment = steadfastFixtureShipment();

        $first = app(ApplySteadfastStatus::class)->handle($shipment, 'out_for_delivery', ShipmentStatusChangeSource::Webhook);
        $second = app(ApplySteadfastStatus::class)->handle($shipment->refresh(), 'out_for_delivery', ShipmentStatusChangeSource::System);

        $shipment->refresh();

        expect($first->unknown)->toBeTrue()
            ->and($first->newlyFlagged)->toBeTrue()
            ->and($second->changed)->toBeFalse()
            ->and($shipment->provider_status)->toBe('out_for_delivery')
            ->and($shipment->needs_review)->toBeTrue()
            ->and($shipment->status)->toBe(OrderCourierStatus::Assigned)
            ->and(DB::table('order_proceeds_settlements')->count())->toBe(0);

        Event::assertDispatchedTimes(SteadfastStatusNeedsReview::class, 1);
        Event::assertDispatched(SteadfastStatusNeedsReview::class, fn ($event) => $event->shipmentId === $shipment->id
            && $event->rawStatus === 'out_for_delivery' && $event->unknownStatus);
    });

    it('does not announce a second reason once already flagged', function () {
        Event::fake([SteadfastStatusNeedsReview::class]);

        $shipment = steadfastFixtureShipment();

        app(ApplySteadfastStatus::class)->handle($shipment, 'hold', ShipmentStatusChangeSource::Webhook);
        app(ApplySteadfastStatus::class)->handle($shipment->refresh(), 'unknown', ShipmentStatusChangeSource::Webhook);

        Event::assertDispatchedTimes(SteadfastStatusNeedsReview::class, 1);
    });
});

describe('the webhook', function () {
    beforeEach(function () {
        $this->token = app(RotateSteadfastWebhookToken::class)->handle($this->staff);
        $this->shipment = steadfastFixtureShipment();
    });

    it('applies a status for a caller holding the Bearer token', function () {
        steadfastStatusPost($this, steadfastStatusPayload($this->shipment, 'delivered'), 'Bearer '.$this->token)
            ->assertOk()
            ->assertExactJson(['status' => 'success']);

        expect($this->shipment->refresh()->status)->toBe(OrderCourierStatus::Delivered);
    });

    it('refuses anyone else with 401 and changes nothing', function (?string $header) {
        steadfastStatusPost($this, steadfastStatusPayload($this->shipment, 'delivered'), $header === null ? null : str_replace('{token}', $this->token, $header))
            ->assertUnauthorized();

        expect($this->shipment->refresh()->status)->toBe(OrderCourierStatus::Assigned)
            ->and(DB::table('courier_webhook_receipts')->count())->toBe(0);
    })->with([
        'no header' => [null],
        'no scheme' => ['{token}'],
        'the wrong scheme' => ['Basic {token}'],
        'a wrong token' => ['Bearer not-the-token'],
        'an empty token' => ['Bearer '],
        'a prefix of the token' => ['Bearer {token}x'],
        'lower-case scheme' => ['bearer {token}'],
    ]);

    it('refuses everyone while no token has been generated', function () {
        DB::table('settings')->where('key', 'courier.steadfast.webhook_token')->delete();
        app(SettingsRepository::class)->flush();

        steadfastStatusPost($this, steadfastStatusPayload($this->shipment, 'delivered'), 'Bearer ')->assertUnauthorized();
        steadfastStatusPost($this, steadfastStatusPayload($this->shipment, 'delivered'), 'Bearer null')->assertUnauthorized();
    });

    it('requires JSON of the documented shape', function () {
        $token = 'Bearer '.$this->token;

        $this->call('POST', route('webhooks.courier.steadfast'), [], [], [], ['HTTP_AUTHORIZATION' => $token, 'CONTENT_TYPE' => 'text/plain'], 'status=delivered')
            ->assertStatus(400);

        steadfastStatusPost($this, ['status' => 'delivered'], $token)
            ->assertStatus(400)
            ->assertJsonPath('fields', ['consignment_id', 'invoice', 'updated_at']);

        steadfastStatusPost($this, steadfastStatusPayload($this->shipment, str_repeat('x', 65)), $token)->assertStatus(400);

        expect($this->shipment->refresh()->status)->toBe(OrderCourierStatus::Assigned);
    });

    it('refuses a body over the size cap', function () {
        steadfastStatusPost($this, steadfastStatusPayload($this->shipment, 'delivered', ['padding' => str_repeat('a', 20000)]), 'Bearer '.$this->token)
            ->assertStatus(413);

        expect($this->shipment->refresh()->status)->toBe(OrderCourierStatus::Assigned);
    });

    it('processes a repeated event once and answers success both times', function () {
        $payload = steadfastStatusPayload($this->shipment, 'delivered');

        steadfastStatusPost($this, $payload, 'Bearer '.$this->token)->assertOk();

        $history = steadfastStatusHistoryCount($this->shipment);

        steadfastStatusPost($this, $payload, 'Bearer '.$this->token)->assertOk()->assertExactJson(['status' => 'success']);

        expect(DB::table('courier_webhook_receipts')->count())->toBe(1)
            ->and(steadfastStatusHistoryCount($this->shipment))->toBe($history);
    });

    it('keeps only a hash of the body, never the body', function () {
        steadfastStatusPost($this, steadfastStatusPayload($this->shipment, 'delivered'), 'Bearer '.$this->token)->assertOk();

        $stored = json_encode(DB::table('courier_webhook_receipts')->get()->all());

        expect($stored)->not->toContain($this->shipment->order->reference)
            ->and($stored)->not->toContain($this->shipment->external_id)
            ->and($stored)->not->toContain('delivered')
            ->and(DB::table('courier_webhook_receipts')->value('payload_hash'))->toHaveLength(64);
    });

    it('answers an unknown invoice with the same success, and moves nothing', function () {
        steadfastStatusPost($this, steadfastStatusPayload($this->shipment, 'delivered', ['invoice' => 'ORD-DOES-NOT-EXIST']), 'Bearer '.$this->token)
            ->assertOk()->assertExactJson(['status' => 'success']);

        expect($this->shipment->refresh()->status)->toBe(OrderCourierStatus::Assigned)
            ->and(DB::table('courier_webhook_receipts')->value('outcome'))->toBe('unmatched');
    });

    it('does not let a known invoice be moved under someone else\'s consignment', function () {
        steadfastStatusPost($this, steadfastStatusPayload($this->shipment, 'delivered', ['consignment_id' => '999']), 'Bearer '.$this->token)->assertOk();

        expect($this->shipment->refresh()->status)->toBe(OrderCourierStatus::Assigned)
            ->and($this->shipment->external_id)->not->toBe('999');
    });

    it('names a parcel recovered by invoice alone, from its first push', function () {
        $this->shipment->forceFill(['external_id' => null])->save();

        steadfastStatusPost($this, steadfastStatusPayload($this->shipment, 'pending', ['consignment_id' => 4455]), 'Bearer '.$this->token)->assertOk();

        expect($this->shipment->refresh()->external_id)->toBe('4455');
    });

    it('ignores the COD amount it carries', function () {
        steadfastStatusPost($this, steadfastStatusPayload($this->shipment, 'delivered', ['cod_amount' => 99999]), 'Bearer '.$this->token)->assertOk();

        expect($this->shipment->refresh()->cod_amount->toDecimal())->toBe('1000.00')
            ->and(DB::table('order_proceeds_settlements')->count())->toBe(0);
    });

    it('has one effect when the poll and the webhook race to the same status', function () {
        Http::fake([STEADFAST_STATUS_BASE.'/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => 'delivered'])]);

        $this->artisan('courier:steadfast-sync')->assertSuccessful();

        $history = steadfastStatusHistoryCount($this->shipment);
        $events = $this->shipment->trackingEvents()->count();

        steadfastStatusPost($this, steadfastStatusPayload($this->shipment, 'delivered'), 'Bearer '.$this->token)->assertOk();

        expect($this->shipment->refresh()->status)->toBe(OrderCourierStatus::Delivered)
            ->and(steadfastStatusHistoryCount($this->shipment))->toBe($history)
            ->and($this->shipment->trackingEvents()->count())->toBe($events);
    });
});

describe('polling', function () {
    it('asks only about parcels that are due, moving, and Steadfast\'s', function () {
        $due = steadfastFixtureShipment();
        $recent = steadfastFixtureShipment(null, ['provider_synced_at' => now()->subMinute()]);
        $final = steadfastFixtureShipment(null, ['provider_status' => 'delivered']);
        $finished = steadfastFixtureShipment();
        app(ApplySteadfastStatus::class)->handle($finished, 'delivered', ShipmentStatusChangeSource::System);
        $finished->forceFill(['provider_synced_at' => now()->subHour()])->save();

        Http::fake([STEADFAST_STATUS_BASE.'/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => 'in_review'])]);

        $this->artisan('courier:steadfast-sync')->expectsOutputToContain('Checked 1, changed 0, failed 0')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/status_by_invoice/'.rawurlencode($due->order->reference)));

        expect($recent->refresh()->provider_synced_at->isAfter(now()->subMinutes(2)))->toBeTrue();
    });

    it('is bounded by the batch size, oldest check first', function () {
        $oldest = steadfastFixtureShipment(null, ['provider_synced_at' => now()->subDays(2)]);
        steadfastFixtureShipment(null, ['provider_synced_at' => now()->subHours(3)]);
        steadfastFixtureShipment(null, ['provider_synced_at' => now()->subHours(2)]);

        Http::fake([STEADFAST_STATUS_BASE.'/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => 'in_review'])]);

        $this->artisan('courier:steadfast-sync', ['--limit' => 1])->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), rawurlencode($oldest->order->reference)));
    });

    it('applies what it finds through the shared action', function () {
        $shipment = steadfastFixtureShipment();

        Http::fake([STEADFAST_STATUS_BASE.'/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => 'delivered'])]);

        $this->artisan('courier:steadfast-sync')->expectsOutputToContain('changed 1')->assertSuccessful();

        expect($shipment->refresh()->status)->toBe(OrderCourierStatus::Delivered);
    });

    it('remembers a failure safely and leaves the parcel alone for longer', function () {
        $shipment = steadfastFixtureShipment();

        // The first check times out; every one after it is answered.
        $asked = 0;

        Http::fake(function () use (&$asked) {
            if ($asked++ === 0) {
                throw new ConnectionException('cURL error 28: timed out');
            }

            return Http::response(['status' => 200, 'delivery_status' => 'in_review']);
        });

        $this->artisan('courier:steadfast-sync')->assertFailed();

        $shipment->refresh();

        expect($shipment->provider_sync_error)->toBe('timeout')
            ->and($shipment->status)->toBe(OrderCourierStatus::Assigned);

        // Fifteen minutes on: past the normal wait, inside the longer one after an error.
        $this->travel(15)->minutes();

        $this->artisan('courier:steadfast-sync')->expectsOutputToContain('Checked 0')->assertSuccessful();

        $this->travel(20)->minutes();

        $this->artisan('courier:steadfast-sync')->expectsOutputToContain('Checked 1')->assertSuccessful();

        expect($shipment->refresh()->provider_sync_error)->toBeNull();
    });

    it('stops the whole batch when the key is rejected', function () {
        steadfastFixtureShipment();
        steadfastFixtureShipment();

        Http::fake([STEADFAST_STATUS_BASE.'/*' => Http::response('', 401)]);

        $this->artisan('courier:steadfast-sync')->assertFailed();

        Http::assertSentCount(1);
    });

    it('flags a parcel Steadfast has never heard of', function () {
        $shipment = steadfastFixtureShipment();

        Http::fake([STEADFAST_STATUS_BASE.'/*' => Http::response('', 404)]);

        $this->artisan('courier:steadfast-sync')->assertSuccessful();

        $shipment->refresh();

        expect($shipment->needs_review)->toBeTrue()
            ->and($shipment->provider_sync_error)->toBe('not_found')
            ->and($shipment->status)->toBe(OrderCourierStatus::Assigned);
    });

    it('does nothing while Steadfast is switched off', function () {
        steadfastFixtureShipment();

        app(ConfigureSteadfast::class)->handle($this->staff, false, null, []);

        Http::fake();

        $this->artisan('courier:steadfast-sync')->expectsOutputToContain('Checked 0')->assertSuccessful();

        Http::assertNothingSent();
    });
});

describe('checking one parcel now', function () {
    it('is for those who manage shipments', function () {
        $shipment = steadfastFixtureShipment();

        Http::fake();

        $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))
            ->post(route('admin.shipments.steadfast-sync', $shipment->public_id))
            ->assertForbidden();

        Http::assertNothingSent();
    });

    it('asks Steadfast and applies the answer', function () {
        $shipment = steadfastFixtureShipment();

        Http::fake([STEADFAST_STATUS_BASE.'/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => 'delivered'])]);

        $this->actingAs($this->staff)->post(route('admin.shipments.steadfast-sync', $shipment->public_id))->assertRedirect();

        expect($shipment->refresh()->status)->toBe(OrderCourierStatus::Delivered);
    });

    it('will not ask Steadfast about a parcel that is not Steadfast\'s', function () {
        $order = steadfastFixtureOrder();
        $manual = app(CreateShipmentFromAllocations::class)->handle(
            $order,
            CourierProviderCode::Manual,
            Money::fromDecimal('80.00', Currency::BDT),
            [[]],
            $this->staff,
        );

        Http::fake();

        $this->actingAs($this->staff)->post(route('admin.shipments.steadfast-sync', $manual->public_id))->assertNotFound();

        Http::assertNothingSent();
    });
});
