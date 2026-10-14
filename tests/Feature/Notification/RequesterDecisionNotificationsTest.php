<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Notification\Actions\NotifyRequesterOfDecision;
use App\Domain\Supplier\Actions\DecideSupplierStockUpdate;
use App\Domain\Supplier\Actions\SubmitSupplierStockUpdate;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierStockUpdate;
use App\Models\User;
use App\Notifications\Requests\RequestDecided;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The person who asked is told what staff decided (§33.2).
 *
 * What these hold: the right person is told, and only them; the bell links to
 * the requester's own scoped screen; a retried decision leaves one entry; and
 * the reviewer's free-text note never reaches the requester's bell.
 *
 * Helpers are prefixed because Pest loads every test file into one global
 * function namespace.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function decisionNotificationPartner(): User
{
    return User::factory()->withBusinessAccount(fn ($account) => $account->active())->create();
}

/**
 * A pending availability update on a fresh offer, and the Supplier who asked.
 *
 * @return array{0: Supplier, 1: SupplierStockUpdate}
 */
function decisionNotificationStockUpdate(): array
{
    $supplier = Supplier::factory()->create();
    $update = app(SubmitSupplierStockUpdate::class)->handle($supplier, supplierTestOffer($supplier), 40, 'Restocked');

    return [$supplier, $update];
}

it('tells the Supplier their availability update was approved, and only them', function () {
    [$supplier, $update] = decisionNotificationStockUpdate();
    $stranger = Supplier::factory()->create();
    $reviewer = testPlatformStaff(PlatformRole::SupplierManager);

    app(DecideSupplierStockUpdate::class)->approve($update, $reviewer->id, 'Internal: checked with the warehouse.');

    $notification = $supplier->notifications()->sole();

    expect($notification->data['event'])->toBe('decision.stock_update.approved')
        ->and($notification->data['reference'])->toStartWith('STK-')
        ->and($stranger->notifications()->count())->toBe(0)
        // The reviewer's note is for the audit trail, not the requester's bell.
        ->and(json_encode($notification->data))->not->toContain('warehouse')
        ->and(array_keys($notification->data))->toBe(['event', 'reference']);
});

it('tells the Supplier when the update was not approved', function () {
    [$supplier, $update] = decisionNotificationStockUpdate();

    app(DecideSupplierStockUpdate::class)->reject($update, testPlatformStaff(PlatformRole::SupplierManager)->id, 'Quantity looks wrong.');

    expect($supplier->notifications()->sole()->data['event'])->toBe('decision.stock_update.rejected');
});

it('shows the Supplier the outcome on their own notifications page, linked to their own stock screen', function () {
    [$supplier, $update] = decisionNotificationStockUpdate();
    app(DecideSupplierStockUpdate::class)->approve($update, testPlatformStaff(PlatformRole::SupplierManager)->id);

    supplierTestSignIn($supplier);

    $this->get(route('supplier.notifications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.0.title', 'Your availability update was approved')
            ->where('notifications.0.href', route('supplier.stock.index'))
            ->where('notifications.0.read', false),
        );
});

it('does not announce a decision twice when it is retried', function () {
    $partner = decisionNotificationPartner();
    $notify = app(NotifyRequesterOfDecision::class);

    $notify->handle($partner, RequestDecided::REFUND, true, 'refund-1', 'RFD-1', ['audience' => 'account', 'payment' => 'pay-1']);
    $notify->handle($partner, RequestDecided::REFUND, true, 'refund-1', 'RFD-1', ['audience' => 'account', 'payment' => 'pay-1']);

    expect($partner->notifications()->count())->toBe(1);
});

it('sends a partner\'s return decision to their own order screen', function () {
    $partner = decisionNotificationPartner();
    $other = decisionNotificationPartner();

    app(NotifyRequesterOfDecision::class)->handle(
        $partner,
        RequestDecided::RETURN,
        false,
        'return-1',
        'RET-0001',
        ['website' => 'site-1', 'order' => 'order-1'],
    );

    $this->actingAs($partner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('notifications.0.title', 'A return was not approved')
        ->where('notifications.0.description', 'Open the order to see the outcome. Reference RET-0001')
        ->where('notifications.0.href', route('websites.orders.show', ['site-1', 'order-1'])),
    );

    // Owner isolation: someone else's bell stays empty.
    $this->actingAs($other)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('notifications', [])
        ->where('unreadNotificationCount', 0),
    );
});

it('sends a refund decision to the requester\'s own receipt, or to payments for the staff member who raised it', function () {
    $partner = decisionNotificationPartner();
    $staff = testPlatformStaff(PlatformRole::PaymentManager);
    $notify = app(NotifyRequesterOfDecision::class);

    $notify->handle($partner, RequestDecided::REFUND, true, 'refund-1', 'RFD-1', ['audience' => 'account', 'payment' => 'pay-1']);
    $notify->handle($staff, RequestDecided::REFUND, false, 'refund-2', 'RFD-2', ['audience' => 'staff', 'payment' => 'pay-2']);

    $this->actingAs($partner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('notifications.0.title', 'Your refund request was approved')
        ->where('notifications.0.href', route('subscription.receipts.show', 'pay-1')),
    );

    $this->actingAs($staff)->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('notifications.0.title', 'Your refund request was not approved')
        ->where('notifications.0.href', route('admin.payments.index')),
    );
});

it('tells nobody when there is no requester to tell', function () {
    app(NotifyRequesterOfDecision::class)->handle(null, RequestDecided::RETURN, true, 'return-1');

    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('has English and Bangla wording for every decision', function () {
    foreach (['en', 'bn'] as $locale) {
        $events = trans('notification.events', [], $locale);

        foreach ([RequestDecided::REFUND, RequestDecided::RETURN, RequestDecided::STOCK_UPDATE] as $kind) {
            foreach (['approved', 'rejected'] as $outcome) {
                expect($events["decision.$kind.$outcome"]['title'] ?? null)->toBeString()->not->toBe('');
            }
        }

        expect(trans('notification.reference', ['reference' => 'X'], $locale))->toContain('X');
    }
});

/*
 * The wiring guard for the decision actions — the refund and return flows are
 * expensive to stage here, and their own suites already run these code paths.
 */
it('has every decision action tell the requester', function (string $file) {
    expect(file_get_contents(base_path($file)))->toContain('NotifyRequesterOfDecision::class');
})->with([
    'refund' => ['app/Domain/Billing/Actions/DecideRefund.php'],
    'return' => ['app/Domain/Order/Actions/DecideOrderReturn.php'],
    'stock update' => ['app/Domain/Supplier/Actions/DecideSupplierStockUpdate.php'],
]);
