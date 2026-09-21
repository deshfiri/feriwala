<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\VerificationCodes;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Order\Actions\ConfirmCodOrder;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\RequestOrderReturn;
use App\Domain\Order\Actions\SendCodConfirmationCode;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The returns screens: the partner's, on their website order, and the staff
 * returns desk (§18.2, §19.1, §26.3, P6-12).
 *
 * Nothing delivers an order yet (fulfilment, §20, P6-17), so the order is moved
 * to delivered by hand the way fulfilment will.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create(['name' => 'Ayesha Fashion']);
    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    websiteTestFee(FeeType::WebsiteDelivery, 0);

    $this->selection = WebsiteProduct::create([
        'website_id' => $this->website->id,
        'business_account_id' => $this->account->id,
        'product_id' => websiteTestProduct()->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price_minor' => 260000,
        'published_at' => now(),
    ]);

    $this->stock = StockItem::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->selection->product_id]);
    app(StockLedger::class)->move($this->stock, null, StockBucket::Available, 10, StockMovementType::Adjustment);

    $this->order = returnScreensDeliveredOrder();
});

/**
 * Two panjabis, paid on delivery, and delivered.
 */
function returnScreensDeliveredOrder(): Order
{
    $money = fn (int $minor) => Money::of($minor, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

    [$order] = app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        reference: 'SF-RS-'.Str::upper(Str::random(6)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address,
        billingAddress: $address,
        items: [['sku' => test()->selection->product->sku, 'quantity' => 2]],
        claimedUnitPrices: [$money(260000)],
        claimedTotals: [
            'subtotal' => $money(520000),
            'discount' => $money(0),
            'shipping' => $money(0),
            'tax' => $money(0),
            'grand_total' => $money(520000),
        ],
        paymentMethod: 'cod',
    ));

    $code = app(VerificationCodes::class)->issue(SendCodConfirmationCode::PURPOSE, $order->public_id);
    app(ConfirmCodOrder::class)->handle($order, $code);
    $order->refresh();

    foreach ([
        OrderStatus::ReadyForFulfillment, OrderStatus::Picking, OrderStatus::Packing,
        OrderStatus::ReadyForPickup, OrderStatus::CourierAssigned, OrderStatus::Shipped, OrderStatus::Delivered,
    ] as $status) {
        $order->moveTo($status, new StatusChange(reason: 'Moved by the test, as fulfilment will.'), OrderStatusChangeSource::System);
    }

    app(StockLedger::class)->move(test()->stock, StockBucket::Processing, StockBucket::Sold, 2, StockMovementType::Adjustment);

    return $order->refresh();
}

function returnScreensAsk(Order $order): OrderReturn
{
    [$return] = app(RequestOrderReturn::class)->handle($order, new ReturnSubmission(
        reason: ReturnReason::Damaged,
        lines: [['sku' => $order->items()->sole()->sku, 'quantity' => 1]],
        customerNote: 'The stitching is undone.',
    ), OrderStatusChangeSource::Storefront);

    return $return;
}

describe('the partner\'s website order', function () {
    it('shows what can still come back, and asks for a return on the customer\'s behalf', function () {
        $this->actingAs($this->account->owner)
            ->get(route('websites.orders.show', [$this->website->public_id, $this->order->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('returnable.eligible', true)
                ->where('returnable.lines.0.returnable', 2)
                ->has('returns', 0)
                ->where('can.request_return', true));

        $this->actingAs($this->account->owner)
            ->post(route('websites.orders.returns.store', [$this->website->public_id, $this->order->public_id]), [
                'reason' => 'damaged',
                'lines' => [$this->selection->product->sku => 1],
                'note' => 'Called the shop about a torn sleeve.',
                'idempotency_key' => 'form-1',
            ])
            ->assertRedirect(route('websites.orders.show', [$this->website->public_id, $this->order->public_id]));

        // The same form sent twice is one return.
        $this->actingAs($this->account->owner)
            ->post(route('websites.orders.returns.store', [$this->website->public_id, $this->order->public_id]), [
                'reason' => 'damaged',
                'lines' => [$this->selection->product->sku => 1],
                'idempotency_key' => 'form-1',
            ]);

        $return = OrderReturn::query()->sole();

        expect($return->source)->toBe(OrderStatusChangeSource::Account)
            ->and($return->requested_by)->toBe($this->account->owner_id);

        $this->actingAs($this->account->owner)
            ->get(route('websites.orders.show', [$this->website->public_id, $this->order->public_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('returns', 1)
                ->where('returns.0.status', ReturnStatus::Requested->value)
                ->where('returns.0.can_cancel', true)
                ->where('returnable.lines.0.returnable', 1));
    });

    it('withdraws a return before goods arrive', function () {
        $return = returnScreensAsk($this->order);

        $this->actingAs($this->account->owner)
            ->post(route('websites.orders.returns.cancellation.store', [$this->website->public_id, $this->order->public_id, $return->public_id]))
            ->assertRedirect();

        expect($return->refresh()->status)->toBe(ReturnStatus::Cancelled);
    });

    it('keeps another partner out, and lets a member read but not ask', function () {
        $other = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1']);

        $this->actingAs($other->owner)
            ->post(route('websites.orders.returns.store', [$this->website->public_id, $this->order->public_id]), [
                'reason' => 'damaged',
                'lines' => [$this->selection->product->sku => 1],
                'idempotency_key' => 'form-2',
            ])
            ->assertNotFound();

        $member = User::factory()->staffOf($this->account, AccountRole::Staff)->create();

        $this->actingAs($member)
            ->get(route('websites.orders.show', [$this->website->public_id, $this->order->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.request_return', false));

        $this->actingAs($member)
            ->post(route('websites.orders.returns.store', [$this->website->public_id, $this->order->public_id]), [
                'reason' => 'damaged',
                'lines' => [$this->selection->product->sku => 1],
                'idempotency_key' => 'form-3',
            ])
            ->assertForbidden();

        expect(OrderReturn::query()->count())->toBe(0);
    });

    it('never shows the partner where the goods went or what staff noted', function () {
        $return = returnScreensAsk($this->order);
        $lead = testPlatformStaff(PlatformRole::OrderManager);
        $lead->assignRole(PlatformRole::InventoryManager->value);

        $this->actingAs($lead)->post(route('admin.returns.approval.store', $return->public_id), [
            'reason' => 'The sleeve is torn as described.',
        ]);
        $this->actingAs($lead)->post(route('admin.returns.receipt.store', $return->public_id), [
            'warehouse' => $this->warehouse->public_id,
            'note' => 'STAFF-ONLY-NOTE',
            'lines' => [$return->items()->sole()->public_id => ['quantity' => 1, 'disposition' => 'damaged']],
        ]);

        $page = $this->actingAs($this->account->owner)
            ->get(route('websites.orders.show', [$this->website->public_id, $this->order->public_id]));

        $returns = (string) json_encode($page->viewData('page')['props']['returns']);

        expect($returns)->toContain('received')
            ->and($returns)->not->toContain('STAFF-ONLY-NOTE')
            ->and($returns)->not->toContain('DHK')
            ->and($returns)->not->toContain('disposition')
            ->and($returns)->not->toContain('warehouse');
    });
});

describe('the staff returns desk', function () {
    beforeEach(function () {
        $this->return = returnScreensAsk($this->order);
    });

    it('lists and shows returns to staff who read orders, and to nobody else', function () {
        $manager = testPlatformStaff(PlatformRole::OrderManager);

        $this->actingAs($manager)
            ->get(route('admin.returns.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/returns/index')
                ->has('returns.data', 1)
                ->where('returns.data.0.reference', $this->return->reference));

        $this->actingAs($manager)
            ->get(route('admin.returns.show', $this->return->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/returns/show')
                ->where('orderReturn.cash_on_delivery', true)
                ->where('can.approve', true)
                ->where('can.receive', false));

        // Owning the shop is never a way onto the desk.
        $this->actingAs($this->account->owner)->get(route('admin.returns.index'))->assertForbidden();
        $this->actingAs(testPlatformStaff(PlatformRole::KycManager))->get(route('admin.returns.index'))->assertForbidden();
    });

    it('approves with a reason, refuses without one, and refuses staff who may not decide', function () {
        $manager = testPlatformStaff(PlatformRole::OrderManager);

        $this->actingAs($manager)
            ->post(route('admin.returns.approval.store', $this->return->public_id), ['reason' => 'ok'])
            ->assertSessionHasErrors('reason');

        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->post(route('admin.returns.approval.store', $this->return->public_id), ['reason' => 'Approving without the right to.'])
            ->assertForbidden();

        expect($this->return->refresh()->status)->toBe(ReturnStatus::Requested);

        $this->actingAs($manager)
            ->post(route('admin.returns.approval.store', $this->return->public_id), ['reason' => 'The sleeve is torn as described.'])
            ->assertRedirect();

        expect($this->return->refresh()->status)->toBe(ReturnStatus::Approved);
    });

    it('takes goods back only with the right to change stock, then starts a refund a person settles', function () {
        $manager = testPlatformStaff(PlatformRole::OrderManager);
        $this->actingAs($manager)->post(route('admin.returns.approval.store', $this->return->public_id), [
            'reason' => 'The sleeve is torn as described.',
        ]);

        $receipt = [
            'warehouse' => $this->warehouse->public_id,
            'lines' => [$this->return->items()->sole()->public_id => ['quantity' => 1, 'disposition' => ReturnDisposition::Damaged->value]],
        ];

        // An order manager may move the return along but not change stock.
        $this->actingAs($manager)
            ->post(route('admin.returns.receipt.store', $this->return->public_id), $receipt)
            ->assertForbidden();

        $lead = testPlatformStaff(PlatformRole::OrderManager);
        $lead->assignRole(PlatformRole::InventoryManager->value);

        $this->actingAs($lead)
            ->post(route('admin.returns.receipt.store', $this->return->public_id), $receipt)
            ->assertRedirect();

        expect($this->return->refresh()->status)->toBe(ReturnStatus::Received)
            ->and($this->stock->refresh()->damaged)->toBe(1);

        $this->actingAs($manager)
            ->post(route('admin.returns.refund.store', $this->return->public_id))
            ->assertRedirect();

        expect($this->return->refresh()->refund_state)->toBe(ReturnRefundState::ManualReview);

        $this->actingAs($manager)
            ->get(route('admin.returns.show', $this->return->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orderReturn.refund.state', 'manual_review')
                // Settling by hand is a reversal of money, not an order right.
                ->where('can.settle_manually', false));

        $payments = testPlatformStaff(PlatformRole::PaymentManager);

        $this->actingAs($payments)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.returns.refund.settlement.store', $this->return->public_id), [
                'how' => 'Paid back in cash at the door, receipt 0042.',
            ])
            ->assertRedirect();

        expect($this->return->refresh()->status)->toBe(ReturnStatus::Refunded)
            ->and($this->return->refund_state)->toBe(ReturnRefundState::Completed);
    });

    it('asks for the password again before recording a refund settled by hand', function () {
        $payments = testPlatformStaff(PlatformRole::PaymentManager);

        $this->actingAs($payments)
            ->post(route('admin.returns.refund.settlement.store', $this->return->public_id), [
                'how' => 'Paid back in cash at the door, receipt 0042.',
            ])
            ->assertRedirect(route('password.confirm'));

        expect($this->return->refresh()->refund_state)->toBe(ReturnRefundState::NotRequired);
    });
});
