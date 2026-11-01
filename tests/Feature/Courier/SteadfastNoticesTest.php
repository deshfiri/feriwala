<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Courier\Actions\ApplySteadfastStatus;
use App\Domain\Courier\Actions\BookSteadfastShipment;
use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Actions\RecordManualCourierStatusUpdate;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Enums\ShipmentStatusChangeSource;
use App\Domain\Notification\Actions\AnnounceCourierEvent;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Models\Order;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/SteadfastFixtures.php';

/*
 * What staff and Partners are told about a Steadfast booking and parcel, and what
 * each sees on the order. Staff hear what needs a person; the Partner hears only
 * confirmed moves of their own parcel. Nothing personal, nothing raw, no
 * credential is ever in a notification or in the Partner's page. HTTP is faked.
 */
const STEADFAST_NOTICE_BASE = 'https://portal.steadfast.com.bd/api/v1';

/** @return list<string> the events a person has been told, oldest first */
function steadfastNoticeEvents(User $user): array
{
    return $user->notifications()->orderBy('created_at')->orderBy('id')->get()->map(fn ($n) => $n->data['event'])->values()->all();
}

function steadfastNoticeOwner(Order $order): User
{
    return BusinessAccount::query()->findOrFail($order->business_account_id)->owner;
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->courier = testPlatformStaff(PlatformRole::CourierManager);
    $this->superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->outsider = testPlatformStaff(PlatformRole::SmsManager);
    steadfastFixtureEnable();
});

describe('staff are told what needs a person', function () {
    it('tells those who manage couriers, and Super Admins, when a booking fails', function () {
        $order = steadfastFixtureOrder();

        Http::fake([STEADFAST_NOTICE_BASE.'/*' => Http::response(['message' => 'bad'], 422)]);

        app(BookSteadfastShipment::class)->handle($order, $this->courier);

        expect(steadfastNoticeEvents($this->courier))->toContain('courier.staff.booking_failed')
            ->and(steadfastNoticeEvents($this->superAdmin))->toContain('courier.staff.booking_failed')
            ->and(steadfastNoticeEvents($this->outsider))->not->toContain('courier.staff.booking_failed')
            // A Partner is a business identity: never told what is waiting for staff.
            ->and(steadfastNoticeEvents(steadfastNoticeOwner($order)))->not->toContain('courier.staff.booking_failed');
    });

    it('tells them when a booking\'s outcome is not known', function () {
        $order = steadfastFixtureOrder();

        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        app(BookSteadfastShipment::class)->handle($order, $this->courier);

        expect(steadfastNoticeEvents($this->courier))->toContain('courier.staff.booking_uncertain')
            ->and(steadfastNoticeEvents(steadfastNoticeOwner($order)))->toBe([]);
    });

    it('carries only the order\'s reference and public id', function () {
        $order = steadfastFixtureOrder();

        Http::fake([STEADFAST_NOTICE_BASE.'/*' => Http::response(['message' => 'bad', 'errors' => ['recipient_phone' => ['01712345678']]], 422)]);

        app(BookSteadfastShipment::class)->handle($order, $this->courier);

        $notice = $this->courier->notifications()->firstOrFail();

        expect(array_keys($notice->data))->toEqualCanonicalizing(['event', 'reference', 'order'])
            ->and($notice->data['reference'])->toBe($order->reference)
            ->and($notice->data['order'])->toBe($order->public_id);

        $stored = json_encode($notice->data);

        foreach (['Rahim', '01712345678', '8801712345678', 'House 12', 'fixture-api-key', 'fixture-secret-key'] as $private) {
            expect($stored)->not->toContain($private);
        }
    });

    it('writes one entry however often it is announced', function () {
        $order = steadfastFixtureOrder();

        Http::fake([STEADFAST_NOTICE_BASE.'/*' => Http::response(['message' => 'bad'], 422)]);

        $attempt = app(BookSteadfastShipment::class)->handle($order, $this->courier);

        app(AnnounceCourierEvent::class)->bookingNeedsAttention('booking_failed', $order, $attempt->public_id);
        app(AnnounceCourierEvent::class)->bookingNeedsAttention('booking_failed', $order, $attempt->public_id);

        expect($this->courier->notifications()->count())->toBe(1);
    });

    it('tells them once about a status Banij does not know, and does not tell the Partner', function () {
        $shipment = steadfastFixtureShipment();

        app(ApplySteadfastStatus::class)->handle($shipment, 'out_for_delivery', ShipmentStatusChangeSource::Webhook);
        app(ApplySteadfastStatus::class)->handle($shipment->refresh(), 'out_for_delivery', ShipmentStatusChangeSource::System);
        app(ApplySteadfastStatus::class)->handle($shipment->refresh(), 'something_else', ShipmentStatusChangeSource::System);

        expect(steadfastNoticeEvents($this->courier))->toBe(['courier.staff.status_unknown'])
            ->and(steadfastNoticeEvents(steadfastNoticeOwner($shipment->order)))->not->toContain('courier.staff.status_unknown');
    });

    it('tells them when Steadfast reports something they must decide', function () {
        $shipment = steadfastFixtureShipment();

        app(ApplySteadfastStatus::class)->handle($shipment, 'partial_delivered', ShipmentStatusChangeSource::Webhook);

        expect(steadfastNoticeEvents($this->courier))->toBe(['courier.staff.status_review']);
    });
});

describe('the Partner is told what happened to their parcel', function () {
    it('hears when a courier is booked', function () {
        $order = steadfastFixtureOrder();

        Http::fake([STEADFAST_NOTICE_BASE.'/*' => Http::response([
            'status' => 200,
            'consignment' => ['consignment_id' => 9001, 'invoice' => $order->reference, 'tracking_code' => 'TRK1', 'status' => 'in_review'],
        ])]);

        app(BookSteadfastShipment::class)->handle($order, $this->courier);

        expect(steadfastNoticeEvents(steadfastNoticeOwner($order)))->toBe(['courier.partner.booked']);
    });

    it('hears each confirmed step once as the parcel is delivered', function () {
        $shipment = steadfastFixtureShipment();

        app(ApplySteadfastStatus::class)->handle($shipment, 'delivered', ShipmentStatusChangeSource::Webhook);
        app(ApplySteadfastStatus::class)->handle($shipment->refresh(), 'delivered', ShipmentStatusChangeSource::System);

        $events = steadfastNoticeEvents(steadfastNoticeOwner($shipment->order));

        expect($events)->toContain('courier.partner.picked_up', 'courier.partner.in_transit', 'courier.partner.delivered')
            ->and(array_count_values($events)['courier.partner.delivered'])->toBe(1);
    });

    it('is never told about a review, an error, or a status Banij could not map', function () {
        $shipment = steadfastFixtureShipment();

        app(ApplySteadfastStatus::class)->handle($shipment, 'hold', ShipmentStatusChangeSource::Webhook);
        app(ApplySteadfastStatus::class)->handle($shipment->refresh(), 'mystery', ShipmentStatusChangeSource::Webhook);

        expect(steadfastNoticeEvents(steadfastNoticeOwner($shipment->order)))->toBe([]);
    });

    it('does not start announcing manual shipments', function () {
        $order = steadfastFixtureOrder();

        $manual = app(CreateShipmentFromAllocations::class)->handle(
            $order,
            CourierProviderCode::Manual,
            Money::fromDecimal('80.00', Currency::BDT),
            [[]],
            $this->courier,
        );

        app(RecordManualCourierStatusUpdate::class)->handle($manual, OrderCourierStatus::PickupRequested, $this->courier);

        expect(steadfastNoticeEvents(steadfastNoticeOwner($order)))->toBe([]);
    });
});

describe('the order page', function () {
    it('gives staff the booking, both statuses, the references and what they may do', function () {
        $shipment = steadfastFixtureShipment(null, ['provider_status' => 'in_review', 'provider_charge' => '65.00']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.store-orders.show', $shipment->order->public_id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('courier.enabled', true)
                ->where('courier.shipment.tracking_number', '15BAEB8A')
                ->where('courier.shipment.consignment_id', $shipment->external_id)
                ->where('courier.shipment.raw_status', 'in_review')
                ->where('courier.shipment.raw_status_label', 'In review')
                ->where('courier.shipment.status', 'assigned')
                ->where('courier.shipment.charge.amount', '65.00')
                ->where('courier.shipment.can_sync', true)
                ->where('courier.can.book', false));
    });

    it('offers booking only for a dispatched order with nothing live', function () {
        $order = steadfastFixtureOrder();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.store-orders.show', $order->public_id))
            ->assertInertia(fn ($page) => $page
                ->where('courier.can.book', true)
                ->where('courier.shipment', null)
                ->where('courier.booking', null));
    });

    it('shows an unrecognised status as such, beside the raw value', function () {
        $shipment = steadfastFixtureShipment(null, ['provider_status' => 'out_for_delivery', 'needs_review' => true]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.store-orders.show', $shipment->order->public_id))
            ->assertInertia(fn ($page) => $page
                ->where('courier.shipment.raw_status', 'out_for_delivery')
                ->where('courier.shipment.raw_status_label', null)
                ->where('courier.shipment.needs_review', true));
    });

    it('gives the Partner only the courier, the tracking code and Banij\'s own status', function () {
        $shipment = steadfastFixtureShipment(null, [
            'provider_status' => 'in_review',
            'provider_charge' => '65.00',
            'needs_review' => true,
            'provider_sync_error' => 'not_found',
        ]);

        $response = $this->actingAs(steadfastNoticeOwner($shipment->order))
            ->get(route('store-orders.show', $shipment->order->public_id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('courier.name', 'Steadfast Courier')
                ->where('courier.tracking_number', '15BAEB8A')
                ->where('courier.status', 'assigned')
                ->has('courier.events')
                ->missing('courier.raw_status')
                ->missing('courier.needs_review')
                ->missing('courier.charge')
                ->missing('courier.urls'));

        // What the page was actually given. Every page also carries the whole
        // translation dictionary, whose keys (`steadfast.raw.in_review`) are not data.
        $props = $response->viewData('page')['props'];
        unset($props['translations']);
        $page = json_encode($props);

        foreach (['in_review', 'not_found', 'needs_review', 'provider_sync_error', 'fixture-api-key', 'fixture-secret-key', (string) $shipment->external_id] as $hidden) {
            expect($page)->not->toContain($hidden);
        }
    });

    it('shows a Partner nothing of another Partner\'s parcel', function () {
        $shipment = steadfastFixtureShipment();
        $stranger = websiteTestAccount();

        $this->actingAs($stranger->owner)
            ->get(route('store-orders.show', $shipment->order->public_id))
            ->assertNotFound();
    });

    it('shows a Partner no panel before a courier is booked', function () {
        $order = steadfastFixtureOrder();

        $this->actingAs(steadfastNoticeOwner($order))
            ->get(route('store-orders.show', $order->public_id))
            ->assertInertia(fn ($page) => $page->where('courier', null));
    });
});
