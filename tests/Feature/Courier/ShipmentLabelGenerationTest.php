<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Courier\Actions\BookSteadfastShipment;
use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Actions\IssueShipmentLabel;
use App\Domain\Courier\Actions\ReconcileSteadfastBooking;
use App\Domain\Courier\Actions\RecordShipmentCourierReferences;
use App\Domain\Courier\Actions\ReissueShipmentLabel;
use App\Domain\Courier\Actions\SynchroniseSteadfastShipment;
use App\Domain\Courier\Enums\CourierBookingStatus;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Exceptions\ShipmentLabelRefused;
use App\Domain\Courier\Jobs\IssueShipmentLabelJob;
use App\Domain\Courier\Models\CourierBookingAttempt;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Notifications\Courier\CourierStaffAlert;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * When a shipping label is issued, what it freezes, and what never happens
 * because of it. Every courier call is faked; nothing reaches Steadfast.
 */

require_once __DIR__.'/ShipmentLabelFixtures.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::CourierManager);
    shipmentLabelEnableSteadfast();

    $this->warehouse = shipmentLabelWarehouse();
    $this->order = shipmentLabelOrder();
    shipmentLabelLine($this->order, $this->warehouse);
});

describe('timing', function () {
    it('issues exactly one label when a consignment is created and saved', function () {
        $shipment = shipmentLabelBook($this->order);

        $label = ShipmentLabel::query()->sole();

        expect($label->shipment_id)->toBe($shipment->id)
            ->and($label->version)->toBe(1)
            ->and($label->consignment_id)->toBe('1424107')
            ->and($label->tracking_code)->toBe('15BAEB8A')
            ->and($label->barcode_value)->toBe('15BAEB8A')
            ->and($shipment->refresh()->warehouse_id)->toBe($this->warehouse->id)
            ->and($shipment->label_blocker)->toBeNull();
    });

    it('issues no label for a failed booking', function () {
        Http::fake([SHIPMENT_LABEL_STEADFAST.'/*' => Http::response(['message' => 'The given data was invalid.', 'errors' => ['recipient_phone' => ['bad']]], 422)]);

        $attempt = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        expect($attempt->status)->toBe(CourierBookingStatus::Failed)
            ->and(Shipment::query()->count())->toBe(0)
            ->and(ShipmentLabel::query()->count())->toBe(0);
    });

    it('issues no label while a booking is uncertain', function () {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $attempt = app(BookSteadfastShipment::class)->handle($this->order, $this->staff);

        expect($attempt->status)->toBe(CourierBookingStatus::Uncertain)
            ->and(ShipmentLabel::query()->count())->toBe(0);
    });

    it('issues the label once when reconciliation recovers the booking, and never books again', function () {
        // Steadfast answered, but Banij failed to record it: the consignment is on the attempt.
        $attempt = CourierBookingAttempt::query()->create([
            'order_id' => $this->order->id,
            'courier_provider_id' => DB::table('courier_providers')->where('code', 'steadfast')->value('id'),
            'attempted_by' => $this->staff->id,
            'status' => CourierBookingStatus::Uncertain,
            'idempotency_key' => 'steadfast:test:'.$this->order->public_id,
            'request_snapshot' => ['invoice' => $this->order->reference, 'cod_amount' => '1250.00', 'consignment_id' => '1424107', 'tracking_code' => '15BAEB8A', 'raw_status' => 'in_review'],
            'started_at' => now(),
        ]);
        Http::fake();

        app(ReconcileSteadfastBooking::class)->handle($attempt, $this->staff);
        app(ReconcileSteadfastBooking::class)->handle($attempt, $this->staff);

        expect(ShipmentLabel::query()->count())->toBe(1)
            ->and(ShipmentLabel::query()->sole()->tracking_code)->toBe('15BAEB8A');
        Http::assertNothingSent();
    });

    it('waits for the courier\'s references when the booking was recovered by invoice alone, then issues once they are recorded', function () {
        $attempt = CourierBookingAttempt::query()->create([
            'order_id' => $this->order->id,
            'courier_provider_id' => DB::table('courier_providers')->where('code', 'steadfast')->value('id'),
            'attempted_by' => $this->staff->id,
            'status' => CourierBookingStatus::Uncertain,
            'idempotency_key' => 'steadfast:test:'.$this->order->public_id,
            'request_snapshot' => ['invoice' => $this->order->reference, 'cod_amount' => '1250.00'],
            'started_at' => now(),
        ]);
        Http::fake([SHIPMENT_LABEL_STEADFAST.'/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => 'in_review'])]);

        app(ReconcileSteadfastBooking::class)->handle($attempt, $this->staff);

        $shipment = Shipment::query()->sole();
        expect(ShipmentLabel::query()->count())->toBe(0)
            ->and($shipment->label_blocker)->toBe('waiting_for_courier');

        app(RecordShipmentCourierReferences::class)->handle($shipment, $this->staff, '1424107', '15BAEB8A', 'Steadfast panel');

        expect(ShipmentLabel::query()->sole()->barcode_value)->toBe('15BAEB8A')
            ->and($shipment->refresh()->label_blocker)->toBeNull();
    });

    it('never makes a second label for a repeated job, poll or retry', function () {
        $shipment = shipmentLabelBook($this->order);

        Http::fake([SHIPMENT_LABEL_STEADFAST.'/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => 'in_review'])]);
        app(SynchroniseSteadfastShipment::class)->handle($shipment);
        IssueShipmentLabelJob::dispatchSync($shipment->id);
        IssueShipmentLabelJob::dispatchSync($shipment->id);
        app(IssueShipmentLabel::class)->handle($shipment, $this->staff);

        expect(ShipmentLabel::query()->count())->toBe(1);
    });

    it('never books the courier again when a label is retried', function () {
        $shipment = shipmentLabelBook($this->order);
        $calls = shipmentLabelBookingCalls();

        Http::fake();
        app(IssueShipmentLabel::class)->handle($shipment, $this->staff);
        IssueShipmentLabelJob::dispatchSync($shipment->id);

        Http::assertNothingSent();
        expect($calls)->toBe(1);
    });

    it('supports a manual shipment that carries a tracking number', function () {
        $shipment = shipmentLabelManual($this->order);

        $label = ShipmentLabel::query()->sole();

        expect($label->shipment_id)->toBe($shipment->id)
            ->and($label->courier_provider_code)->toBe('manual')
            ->and($label->barcode_value)->toBe('MAN-0001');
    });

    it('does not move the shipment or the order when it issues a label', function () {
        $shipment = shipmentLabelManual($this->order, tracking: null);
        $orderStatus = $this->order->refresh()->courier_status;

        $shipment->forceFill(['tracking_number' => 'LATE-001'])->save();
        app(IssueShipmentLabel::class)->handle($shipment, $this->staff);

        expect($shipment->refresh()->status)->toBe(OrderCourierStatus::Assigned)
            ->and($this->order->refresh()->courier_status)->toBe($orderStatus)
            ->and($this->order->status)->toBe(OrderStatus::Shipped);
    });

    it('keeps the consignment when the label cannot be issued', function () {
        $this->warehouse->forceFill(['usable_for_courier_labels' => false])->save();

        $shipment = shipmentLabelBook($this->order);

        expect($shipment)->not->toBeNull()
            ->and(CourierBookingAttempt::query()->sole()->status)->toBe(CourierBookingStatus::Booked)
            ->and($shipment->label_blocker)->toBe('warehouse_not_ready')
            ->and(ShipmentLabel::query()->count())->toBe(0);
    });
});

describe('the frozen snapshot', function () {
    it('takes the Storefront from the order, never from whoever is signed in', function () {
        $other = shipmentLabelStorefront(overrides: ['name' => 'Another Partner Shop']);
        $this->actingAs($other->businessAccount->owner);

        shipmentLabelBook($this->order);

        expect(ShipmentLabel::query()->sole())
            ->website_id->toBe($this->order->website_id)
            ->store_name->toBe('Rupkotha Fashion')
            ->store_phone->toBe('01911111111');
    });

    it('returns to the fulfilling Banij warehouse, never to the Storefront or the Partner', function () {
        shipmentLabelBook($this->order);

        $label = ShipmentLabel::query()->sole();
        $frozen = json_encode($label->getAttributes(), JSON_UNESCAPED_UNICODE);

        expect($label->warehouse_name)->toBe('Banij Tejgaon Hub')
            ->and($label->warehouse_contact_name)->toBe('Karim Dispatch')
            ->and($label->warehouse_phone)->toBe('01800000001')
            ->and($label->warehouse_return_address)->toBe(['line' => '12 Tejgaon Industrial Area', 'area' => 'Tejgaon', 'district' => 'Dhaka', 'postcode' => '1208'])
            ->and($frozen)->not->toContain('STOREFRONT-CONTACT-ADDRESS')
            ->and($frozen)->not->toContain('Internal stock note');
    });

    it('freezes the delivery details, so later changes do not reach an issued label', function () {
        shipmentLabelBook($this->order);
        $before = ShipmentLabel::query()->sole()->getAttributes();

        // The order's own delivery columns cannot change outside a D38 revision,
        // and a revision of them is refused while the booking is live; the
        // warehouse and the Storefront can change freely.
        $this->warehouse->forceFill(['return_address_line' => 'Moved to Gazipur', 'courier_phone' => '01899999999'])->save();
        $this->order->website->forceFill(['name' => 'Renamed Store', 'contact_phone' => '01700000000'])->save();

        expect(ShipmentLabel::query()->sole()->getAttributes())->toBe($before);
    });

    it('refuses to rewrite or delete an issued label in the database', function () {
        shipmentLabelBook($this->order);
        $label = ShipmentLabel::query()->sole();

        // Each refusal in a savepoint of its own, so the test's transaction survives it.
        expect(fn () => DB::transaction(fn () => DB::table('shipment_labels')->where('id', $label->id)->update(['recipient_name' => 'Someone else'])))
            ->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('shipment_labels')->where('id', $label->id)->delete()))
            ->toThrow(QueryException::class);
        expect(ShipmentLabel::query()->sole()->recipient_name)->toBe('Rahim Uddin');
    });

    it('carries no email, cost, margin or note', function () {
        shipmentLabelBook($this->order);

        $frozen = json_encode(ShipmentLabel::query()->sole()->getAttributes(), JSON_UNESCAPED_UNICODE);

        expect($frozen)->not->toContain('rahim.private@example.test')
            ->and($frozen)->not->toContain('store@example.test')
            ->and($frozen)->not->toContain('400.00')
            ->and($frozen)->not->toContain('500.00');
    });

    it('records cash on delivery and prepaid parcels', function () {
        $cod = shipmentLabelManual($this->order, cod: '1250.00');
        expect(ShipmentLabel::query()->where('shipment_id', $cod->id)->sole())
            ->is_prepaid->toBeFalse()
            ->and(ShipmentLabel::query()->where('shipment_id', $cod->id)->sole()->cod_amount->toDecimal())->toBe('1250.00');

        $paidOrder = shipmentLabelOrder(overrides: ['paid_at' => now()]);
        shipmentLabelLine($paidOrder, $this->warehouse);
        $prepaid = shipmentLabelManual($paidOrder, cod: null);

        expect(ShipmentLabel::query()->where('shipment_id', $prepaid->id)->sole())
            ->is_prepaid->toBeTrue()
            ->and(ShipmentLabel::query()->where('shipment_id', $prepaid->id)->sole()->cod_amount->toDecimal())->toBe('0.00');
    });

    it('freezes what is in the parcel by name, variation and quantity, never its price', function () {
        $this->order->items()->first()->forceFill(['variant_label' => 'Size L'])->save();
        $line = $this->order->items()->first();

        shipmentLabelManual($this->order);
        $label = ShipmentLabel::query()->sole();

        expect($label->items)->toBe([['name' => $line->product_name, 'variant' => 'Size L', 'sku' => $line->sku, 'quantity' => 2]])
            ->and($label->item_quantity)->toBe(2)
            ->and(json_encode($label->getAttributes()))->not->toContain('625.00');
    });

    it('freezes the parcel weight only when every package has one recorded', function (array $packages, ?string $expected) {
        app(CreateShipmentFromAllocations::class)->handle(
            $this->order, CourierProviderCode::Manual, Money::fromDecimal('80.00', Currency::BDT), $packages,
            $this->staff, 'MAN-0001', Money::fromDecimal('1250.00', Currency::BDT),
        );

        expect(ShipmentLabel::query()->sole()->parcel_weight_kg)->toBe($expected);
    })->with([
        'two weighed packages, summed' => [[['weight' => '0.5'], ['weight' => '0.54']], '1.040'],
        'one package unweighed' => [[['weight' => '0.5'], ['weight' => null]], null],
    ]);

    it('refuses to call an unpaid cash-on-delivery parcel paid', function () {
        $shipment = shipmentLabelManual($this->order, cod: null);

        expect($shipment->refresh()->label_blocker)->toBe('cod_unknown')
            ->and(ShipmentLabel::query()->count())->toBe(0);
    });
});

describe('refusals', function () {
    it('refuses without a recipient phone, and flags it for staff', function () {
        Notification::fake();
        $order = shipmentLabelOrder(overrides: ['customer' => ['name' => 'Rahim Uddin', 'mobile' => null]]);
        shipmentLabelLine($order, $this->warehouse);

        $shipment = shipmentLabelManual($order);

        expect($shipment->refresh()->label_blocker)->toBe('recipient_incomplete')
            ->and(ShipmentLabel::query()->count())->toBe(0);
        Notification::assertSentTo(
            $this->staff,
            CourierStaffAlert::class,
        );
    });

    it('refuses when the warehouse return address is incomplete', function () {
        expect(fn () => DB::transaction(fn () => $this->warehouse->forceFill(['usable_for_courier_labels' => true, 'return_district' => null])->save()))
            ->toThrow(QueryException::class);

        $this->warehouse->refresh()->forceFill(['usable_for_courier_labels' => false])->save();
        $shipment = shipmentLabelManual($this->order);

        expect($shipment->refresh()->label_blocker)->toBe('warehouse_not_ready');
    });

    it('refuses, never guesses, when the goods come from two warehouses', function () {
        shipmentLabelLine($this->order, shipmentLabelWarehouse(['name' => 'Banij Chattogram Hub']));

        $shipment = shipmentLabelManual($this->order);

        expect($shipment->refresh()->warehouse_id)->toBeNull()
            ->and($shipment->label_blocker)->toBe('multiple_warehouses')
            ->and(__('shipping_label.refused.multiple_warehouses'))->toBe('This order requires fulfilment from multiple warehouses and cannot use a single parcel.');
    });

    it('refuses, never guesses, when the goods are a Partner\'s own unallocated stock', function () {
        $order = shipmentLabelOrder();
        $line = shipmentLabelLine($order, $this->warehouse);
        DB::table('order_item_allocations')->where('order_item_id', $line->id)->update(['status' => 'released', 'released_at' => now()]);

        $shipment = shipmentLabelManual($order);

        expect($shipment->refresh()->label_blocker)->toBe('warehouse_undetermined');
    });

    it('waits, without flagging staff, while the courier\'s references are missing', function () {
        $shipment = shipmentLabelManual($this->order, tracking: null);

        expect($shipment->refresh()->label_blocker)->toBe('waiting_for_courier');
    });
});

describe('corrections', function () {
    it('makes a new version with a reason and keeps the old one', function () {
        $shipment = shipmentLabelManual($this->order);
        $first = ShipmentLabel::query()->sole();

        $this->warehouse->forceFill(['courier_phone' => '01811111111'])->save();
        $second = app(ReissueShipmentLabel::class)->handle($shipment, $this->staff, 'Hub phone number corrected');

        $first->refresh();

        expect($second->version)->toBe(2)
            ->and($second->warehouse_phone)->toBe('01811111111')
            ->and($second->issue_reason)->toBe('Hub phone number corrected')
            ->and($first->warehouse_phone)->toBe('01800000001')
            ->and($first->superseded_at)->not->toBeNull()
            ->and($first->superseded_reason)->toBe('Hub phone number corrected')
            ->and($first->superseded_by_label_id)->toBe($second->id)
            ->and(ShipmentLabel::query()->current()->sole()->id)->toBe($second->id);
    });

    it('refuses a correction that would change nothing', function () {
        $shipment = shipmentLabelManual($this->order);

        expect(fn () => app(ReissueShipmentLabel::class)->handle($shipment, $this->staff, 'just because'))
            ->toThrow(ShipmentLabelRefused::class);
        expect(ShipmentLabel::query()->count())->toBe(1);
    });
});

describe('several parcels', function () {
    it('gives each parcel its own label, warehouse and "Parcel N of M"', function () {
        $first = shipmentLabelManual($this->order);
        $chattogram = shipmentLabelWarehouse(['name' => 'Banij Chattogram Hub', 'return_address_line' => '7 Agrabad C/A', 'return_district' => 'Chattogram']);
        $second = shipmentLabelExtraParcel($this->order, $chattogram, 'MAN-0002');

        app(IssueShipmentLabel::class)->handle($second, $this->staff);

        $firstLabel = ShipmentLabel::query()->where('shipment_id', $first->id)->sole();
        $secondLabel = ShipmentLabel::query()->where('shipment_id', $second->id)->sole();

        expect($firstLabel->parcel_number)->toBe(1)
            ->and($firstLabel->parcel_count)->toBe(1) // frozen before the second parcel existed
            ->and($firstLabel->warehouse_name)->toBe('Banij Tejgaon Hub')
            ->and($secondLabel->parcel_number)->toBe(2)
            ->and($secondLabel->parcel_count)->toBe(2)
            ->and($secondLabel->warehouse_name)->toBe('Banij Chattogram Hub')
            ->and($secondLabel->warehouse_return_address['line'])->toBe('7 Agrabad C/A')
            ->and($secondLabel->store_name)->toBe($firstLabel->store_name)
            // Which lines are in which parcel is not recorded: no list rather than a guess.
            ->and($firstLabel->items)->not->toBeNull()
            ->and($secondLabel->items)->toBeNull();
    });
});

describe('notifications', function () {
    it('tells the owning Partner the label is ready, once, without the customer\'s details', function () {
        $shipment = shipmentLabelBook($this->order);
        IssueShipmentLabelJob::dispatchSync($shipment->id);

        $owner = $this->order->businessAccount->owner;
        $rows = $owner->notifications()->get()->filter(fn ($row) => ($row->data['event'] ?? null) === 'courier.partner.label_ready');

        expect($rows)->toHaveCount(1);

        $data = json_encode($rows->first()->data, JSON_UNESCAPED_UNICODE);

        expect($data)->toContain($this->order->public_id)
            ->and($data)->not->toContain('01712345678')
            ->and($data)->not->toContain('House 12')
            ->and($data)->not->toContain('/label');
    });
});
