<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Courier\Actions\BookSteadfastShipment;
use App\Domain\Courier\Actions\ConfigureSteadfast;
use App\Domain\Courier\Actions\MarkSteadfastBookingNotBooked;
use App\Domain\Courier\Actions\ReconcileSteadfastBooking;
use App\Domain\Courier\Actions\RecordSteadfastBooking;
use App\Domain\Courier\Enums\CourierBookingStatus;
use App\Domain\Courier\Exceptions\CourierBookingRefused;
use App\Domain\Courier\Exceptions\CourierProviderUnavailable;
use App\Domain\Courier\Models\CourierBookingAttempt;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Booking with Steadfast. Every call is a faked HTTP response -- nothing here
 * reaches Steadfast and nothing creates a real consignment. The shapes are the
 * ones Steadfast's maintained package documents (POST /create_order,
 * GET /status_by_invoice/{invoice}).
 */
const STEADFAST_BOOKING_BASE = 'https://portal.steadfast.com.bd/api/v1';
const STEADFAST_BOOKING_PHONE = '01712345678';

function steadfastBookingEnable(): void
{
    app(ConfigureSteadfast::class)->handle(
        testPlatformStaff(PlatformRole::CourierManager),
        true,
        null,
        ['api_key' => 'booking-api-key', 'secret_key' => 'booking-secret-key'],
    );
}

/**
 * How many create_order requests have gone out. Counted by what was recorded,
 * so a request that failed on the wire is not mistaken for one that never went.
 */
function steadfastBookingCreateCalls(): int
{
    return Http::recorded(fn (Request $request) => $request->method() === 'POST')->count();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function steadfastBookingOrder(array $overrides = []): Order
{
    $source = $overrides['source'] ?? OrderSource::Website;

    // A website order must carry its website, customer and storefront reference
    // (orders_website_is_complete); any other source must carry none of them.
    $website = [];

    if ($source === OrderSource::Website) {
        $account = testBusinessAccount(AccountStatus::Active);
        $site = Website::factory()->forAccount($account)->active()->create();
        $customer = WebsiteCustomer::create([
            'website_id' => $site->id,
            'mobile' => '+88017'.random_int(10000000, 99999999),
            'name' => 'Rahim Uddin',
        ]);

        $website = [
            'business_account_id' => $account->id,
            'website_id' => $site->id,
            'website_customer_id' => $customer->id,
            'storefront_order_reference' => 'SF-'.Str::upper(Str::random(8)),
            'placed_by' => null,
            'cart_id' => null,
        ];
    }

    return Order::factory()->create([
        'source' => $source,
        // Courier and delivery start only once a Store Order is dispatched.
        'status' => OrderStatus::Shipped,
        'customer' => ['name' => 'Rahim Uddin', 'mobile' => '+8801712345678', 'email' => 'rahim@example.test'],
        'shipping_address' => [
            'line1' => 'House 12, Road 5',
            'line2' => null,
            'city' => 'Dhaka',
            'district' => 'Dhaka',
            'postcode' => '1216',
            'country' => 'BD',
        ],
        ...$website,
        ...$overrides,
    ]);
}

/**
 * @return array<string, mixed>
 */
function steadfastBookingCreated(Order $order, string $consignmentId = '1424107'): array
{
    return [
        'status' => 200,
        'message' => 'Consignment has been created successfully.',
        'consignment' => [
            'consignment_id' => (int) $consignmentId,
            'invoice' => $order->reference,
            'tracking_code' => '15BAEB8A',
            'recipient_name' => 'Rahim Uddin',
            'status' => 'in_review',
        ],
    ];
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::CourierManager);
    steadfastBookingEnable();
    $this->order = steadfastBookingOrder();
});

describe('a successful booking', function () {
    it('sends exactly the documented fields, built from the order', function () {
        Http::fake([STEADFAST_BOOKING_BASE.'/*' => Http::response(steadfastBookingCreated($this->order))]);

        $attempt = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && $request->url() === STEADFAST_BOOKING_BASE.'/create_order'
                && $request->header('Api-Key') === ['booking-api-key']
                && $request->header('Secret-Key') === ['booking-secret-key']
                && array_keys($body) === ['invoice', 'recipient_name', 'recipient_phone', 'recipient_address', 'cod_amount']
                && $body['invoice'] === $this->order->reference
                && $body['recipient_name'] === 'Rahim Uddin'
                && $body['recipient_phone'] === STEADFAST_BOOKING_PHONE
                && $body['recipient_address'] === 'House 12, Road 5, Dhaka, 1216'
                // An exact number token, written without passing through a float.
                && str_ends_with($request->body(), ',"cod_amount":1000}');
        });

        $shipment = $attempt->shipment;

        expect($attempt->status)->toBe(CourierBookingStatus::Booked)
            ->and($shipment->external_id)->toBe('1424107')
            ->and($shipment->tracking_number)->toBe('15BAEB8A')
            ->and($shipment->provider_status)->toBe('in_review')
            ->and($shipment->provider_charge)->toBeNull()
            ->and($shipment->needs_review)->toBeFalse()
            ->and($shipment->cod_amount->toDecimal())->toBe('1000.00');
    });

    it('sends zero to collect for an order already paid', function () {
        $this->order->payment->forceFill(['gateway' => 'sslcommerz'])->save();

        Http::fake([STEADFAST_BOOKING_BASE.'/*' => Http::response(steadfastBookingCreated($this->order))]);

        app(BookSteadfastShipment::class)->handle($this->order->refresh(), $this->staff);

        Http::assertSent(fn (Request $request) => str_ends_with($request->body(), ',"cod_amount":0}'));
    });

    it('only asks a courier to take the parcel: no dispatch, no stock, no COD, no profit', function () {
        Http::fake([STEADFAST_BOOKING_BASE.'/*' => Http::response(steadfastBookingCreated($this->order))]);

        $fulfilment = $this->order->fulfilment_status;

        app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        $this->order->refresh();

        expect($this->order->courier_status)->toBe(OrderCourierStatus::Assigned)
            ->and($this->order->delivery_status)->toBe(OrderDeliveryStatus::CourierAssigned)
            ->and($this->order->fulfilment_status)->toBe($fulfilment)
            ->and(DB::table('order_proceeds_settlements')->count())->toBe(0);
    });

    it('is refused a second time, with no second call', function () {
        Http::fake([STEADFAST_BOOKING_BASE.'/*' => Http::response(steadfastBookingCreated($this->order))]);

        app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        expect(fn () => app(BookSteadfastShipment::class)->handle($this->order->refresh(), $this->staff))
            ->toThrow(CourierBookingRefused::class, 'already booked');

        Http::assertSentCount(1);
        expect(Shipment::query()->count())->toBe(1);
    });

    it('keeps the customer out of the attempt, the audit trail and the logs', function () {
        Http::fake([STEADFAST_BOOKING_BASE.'/*' => Http::response(steadfastBookingCreated($this->order))]);

        app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        $stored = json_encode([
            DB::table('courier_booking_attempts')->get()->all(),
            AuditLog::query()->where('module', 'courier')->get()->map->getAttributes()->all(),
        ]);

        foreach (['Rahim', STEADFAST_BOOKING_PHONE, '8801712345678', 'House 12', 'rahim@example.test', 'booking-secret-key', 'booking-api-key'] as $private) {
            expect($stored)->not->toContain($private);
        }
    });
});

describe('a booking that is refused before any call', function () {
    it('names what is wrong and leaves no attempt', function (array $overrides, string $message) {
        $order = steadfastBookingOrder($overrides);

        Http::fake();

        expect(fn () => app(BookSteadfastShipment::class)->handle($order, $this->staff))
            ->toThrow(CourierBookingRefused::class, $message);

        Http::assertNothingSent();
        expect(CourierBookingAttempt::query()->count())->toBe(0);
    })->with([
        'a wholesale order' => [['source' => OrderSource::ErpWholesale], 'Only Store Orders'],
        'an order not yet dispatched' => [['status' => OrderStatus::PaymentPending], 'Dispatch this order'],
        'no recipient' => [['customer' => ['mobile' => '+8801712345678']], 'no recipient name'],
        'a number that is not Bangladeshi' => [['customer' => ['name' => 'A', 'mobile' => '+14155550123']], 'valid Bangladesh mobile'],
        'no number' => [['customer' => ['name' => 'A']], 'valid Bangladesh mobile'],
        'no address' => [['shipping_address' => ['city' => 'Dhaka']], 'no delivery address'],
    ]);

    it('does nothing while Steadfast is switched off', function () {
        app(ConfigureSteadfast::class)->handle($this->staff, false, null, []);

        Http::fake();

        expect(fn () => app(BookSteadfastShipment::class)->handle($this->order, $this->staff))
            ->toThrow(CourierProviderUnavailable::class);

        Http::assertNothingSent();
    });

    it('does not start while another attempt for the order is live', function () {
        Http::fake();

        DB::table('courier_booking_attempts')->insert([
            'public_id' => (string) Str::ulid(),
            'order_id' => $this->order->id,
            'courier_provider_id' => DB::table('courier_providers')->where('code', 'steadfast')->value('id'),
            'status' => 'pending',
            'idempotency_key' => Str::random(40),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => app(BookSteadfastShipment::class)->handle($this->order, $this->staff))
            ->toThrow(CourierBookingRefused::class, 'already in progress');

        Http::assertNothingSent();
        expect(CourierBookingAttempt::query()->count())->toBe(1);
    });
});

describe('a definite failure', function () {
    it('is recorded safely and can be tried again', function (int $status) {
        Http::fake([STEADFAST_BOOKING_BASE.'/*' => Http::sequence()
            ->push(['message' => 'The given data was invalid.', 'errors' => ['recipient_phone' => ['bad']]], $status)
            ->push(steadfastBookingCreated($this->order))]);

        $failed = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        expect($failed->status)->toBe(CourierBookingStatus::Failed)
            ->and($failed->shipment_id)->toBeNull()
            ->and($failed->failure_message)->not->toContain('recipient_phone')
            ->and(Shipment::query()->count())->toBe(0)
            ->and($this->order->refresh()->courier_status)->toBe(OrderCourierStatus::Unassigned);

        $retried = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        expect($retried->status)->toBe(CourierBookingStatus::Booked)
            ->and(CourierBookingAttempt::query()->count())->toBe(2);
    })->with([422, 401, 403]);

    it('is a failure when the host could not even be found', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $attempt = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        expect($attempt->status)->toBe(CourierBookingStatus::Failed);
    });
});

describe('an outcome that is not known', function () {
    it('becomes uncertain, and is never booked again blind', function (callable $fake) {
        Http::fake($fake);

        $attempt = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        expect($attempt->status)->toBe(CourierBookingStatus::Uncertain)
            ->and(Shipment::query()->count())->toBe(0);

        $calls = steadfastBookingCreateCalls();

        expect(fn () => app(BookSteadfastShipment::class)->handle($this->order->refresh(), $this->staff))
            ->toThrow(CourierBookingRefused::class, 'unknown outcome');

        expect(steadfastBookingCreateCalls())->toBe($calls)
            ->and(CourierBookingAttempt::query()->count())->toBe(1);
    })->with([
        'a timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
        'a connection that dropped' => [fn () => throw new ConnectionException('cURL error 56: Recv failure')],
        'a server error' => [fn () => Http::response('<html>Bad gateway</html>', 502)],
        'an answer with no consignment' => [fn () => Http::response(['status' => 200, 'message' => 'ok'])],
        'an answer about another invoice' => [fn () => Http::response(['status' => 200, 'consignment' => ['consignment_id' => 5, 'invoice' => 'SOMEONE-ELSE']])],
    ]);

    it('is settled from the invoice lookup when Steadfast does hold the parcel', function () {
        Http::fake([STEADFAST_BOOKING_BASE.'/create_order' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        $attempt = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        Http::fake([STEADFAST_BOOKING_BASE.'/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => 'in_review'])]);

        $settled = app(ReconcileSteadfastBooking::class)->handle($attempt, $this->staff);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === STEADFAST_BOOKING_BASE.'/status_by_invoice/'.rawurlencode($this->order->reference));
        $shipment = $settled->shipment;

        expect($settled->status)->toBe(CourierBookingStatus::Booked)
            ->and($shipment->provider_status)->toBe('in_review')
            // That answer carries no consignment id or tracking code, so a person is asked to look.
            ->and($shipment->external_id)->toBeNull()
            ->and($shipment->needs_review)->toBeTrue()
            ->and(Shipment::query()->count())->toBe(1);
    });

    it('never calls create_order again while reconciling', function () {
        Http::fake([STEADFAST_BOOKING_BASE.'/create_order' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        $attempt = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        Http::fake([STEADFAST_BOOKING_BASE.'/status_by_invoice/*' => Http::response('', 404)]);

        $calls = steadfastBookingCreateCalls();

        app(ReconcileSteadfastBooking::class)->handle($attempt, $this->staff);

        expect(steadfastBookingCreateCalls())->toBe($calls);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
    });

    it('is failed, and bookable again, only when Steadfast clearly has no such parcel', function () {
        $created = 0;

        // The first create_order times out; the one after the reconcile succeeds.
        Http::fake([
            STEADFAST_BOOKING_BASE.'/create_order' => function () use (&$created) {
                if ($created++ === 0) {
                    throw new ConnectionException('cURL error 28: timed out');
                }

                return Http::response(steadfastBookingCreated($this->order));
            },
            STEADFAST_BOOKING_BASE.'/status_by_invoice/*' => Http::response('', 404),
        ]);

        $attempt = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        $settled = app(ReconcileSteadfastBooking::class)->handle($attempt, $this->staff);

        expect($settled->status)->toBe(CourierBookingStatus::Failed)
            ->and($settled->failure_code)->toBe('not_found_at_provider');

        expect(app(BookSteadfastShipment::class)->handle($this->order->refresh(), $this->staff)->status)->toBe(CourierBookingStatus::Booked);
    });

    it('stays uncertain when the lookup is inconclusive', function (callable $fake) {
        Http::fake([STEADFAST_BOOKING_BASE.'/create_order' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        $attempt = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        Http::fake($fake);

        $settled = app(ReconcileSteadfastBooking::class)->handle($attempt, $this->staff);

        expect($settled->status)->toBe(CourierBookingStatus::Uncertain)
            ->and(Shipment::query()->count())->toBe(0);
    })->with([
        'a server error' => [fn () => Http::response('oops', 500)],
        'a timeout' => [fn () => throw new ConnectionException('cURL error 28: timed out')],
        'a rejected key' => [fn () => Http::response('', 401)],
        'an unreadable answer' => [fn () => Http::response('<html>', 200)],
    ]);

    it('finishes locally, with no call, when Steadfast confirmed and Banij could not record it', function () {
        Http::fake([STEADFAST_BOOKING_BASE.'/*' => Http::response(steadfastBookingCreated($this->order, '777'))]);

        // Steadfast answers, then Banij cannot write its own records (once).
        $this->instance(RecordSteadfastBooking::class, Mockery::mock(RecordSteadfastBooking::class, function ($mock) {
            $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('database went away'));
        }));

        $attempt = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        expect($attempt->status)->toBe(CourierBookingStatus::Uncertain)
            ->and($attempt->failure_code)->toBe('local_failure')
            ->and($attempt->request_snapshot['consignment_id'])->toBe('777')
            ->and(Shipment::query()->count())->toBe(0);

        app()->forgetInstance(RecordSteadfastBooking::class);

        $calls = Http::recorded()->count();

        $settled = app(ReconcileSteadfastBooking::class)->handle($attempt, $this->staff);

        // Nothing at all went to Steadfast: the consignment id was already known.
        expect(Http::recorded()->count())->toBe($calls);

        expect($settled->status)->toBe(CourierBookingStatus::Booked)
            ->and($settled->shipment->external_id)->toBe('777')
            ->and($settled->shipment->tracking_number)->toBe('15BAEB8A')
            ->and($settled->shipment->needs_review)->toBeFalse();
    });

    it('can be confirmed as not booked by a person with a reason, on the record', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $attempt = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        expect(fn () => app(MarkSteadfastBookingNotBooked::class)->handle($attempt, $this->staff, '  '))
            ->toThrow(InvalidArgumentException::class);

        $settled = app(MarkSteadfastBookingNotBooked::class)->handle($attempt, $this->staff, 'Not in the Steadfast panel.');

        expect($settled->status)->toBe(CourierBookingStatus::Failed)
            ->and($settled->failure_code)->toBe('staff_confirmed_not_booked');

        $audit = AuditLog::query()->where('action', 'courier.booking_confirmed_not_booked')->firstOrFail();

        expect($audit->reason)->toBe('Not in the Steadfast panel.')
            ->and($audit->actor_id)->toBe($this->staff->id);

        expect(fn () => app(MarkSteadfastBookingNotBooked::class)->handle($settled, $this->staff, 'again'))
            ->toThrow(CourierBookingRefused::class);
    });
});

describe('over HTTP', function () {
    it('is for those who manage shipments', function () {
        Http::fake();

        $outsider = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($outsider)->post(route('admin.orders.steadfast-booking.store', $this->order->public_id))->assertForbidden();

        Http::assertNothingSent();
        expect(CourierBookingAttempt::query()->count())->toBe(0);
    });

    it('books for a courier manager, and refuses with a message rather than an error page', function () {
        Http::fake([STEADFAST_BOOKING_BASE.'/*' => Http::response(steadfastBookingCreated($this->order))]);

        $this->actingAs($this->staff)->post(route('admin.orders.steadfast-booking.store', $this->order->public_id))->assertRedirect();

        expect(CourierBookingAttempt::query()->firstOrFail()->status)->toBe(CourierBookingStatus::Booked);

        $this->actingAs($this->staff)->post(route('admin.orders.steadfast-booking.store', $this->order->public_id))
            ->assertSessionHasErrors('steadfast');

        Http::assertSentCount(1);
    });

    it('reconciles and confirms not-booked only through its own permission check', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $attempt = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);
        $outsider = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($outsider)->post(route('admin.courier.booking-attempts.reconcile', $attempt->public_id))->assertForbidden();
        $this->actingAs($outsider)->post(route('admin.courier.booking-attempts.not-booked', $attempt->public_id), ['reason' => 'x'])->assertForbidden();

        expect($attempt->refresh()->status)->toBe(CourierBookingStatus::Uncertain);
    });

    it('will not confirm a booking as not booked without a reason', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $attempt = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        // Unlike most staff actions, no "No reason given." is filled in for this one.
        $this->actingAs($this->staff)->post(route('admin.courier.booking-attempts.not-booked', $attempt->public_id))
            ->assertSessionHasErrors('reason');

        expect($attempt->refresh()->status)->toBe(CourierBookingStatus::Uncertain);
    });
});
