<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Notification\Actions\NotifyStaffOfSubmission;
use App\Domain\Notification\Enums\StaffQueue;
use App\Domain\Supplier\Actions\SubmitSupplierStockUpdate;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Models\User;
use App\Notifications\Staff\WorkItemSubmitted;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Staff are told when work arrives in a queue they can act on (§33.2).
 *
 * What these hold: only people who could open the queue — plus Super Admins —
 * are told, a retried announcement writes one bell entry, the stored payload is
 * a reference and nothing else, and every one of the ten submit/request actions
 * actually makes the call.
 *
 * Helpers are prefixed because Pest loads every test file into one global
 * function namespace.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * A platform staff member who holds `$queue`'s view permission and nothing
 * else of interest — SMS Manager has no queue permission of its own.
 */
function staffQueueHolder(StaffQueue $queue): User
{
    $staff = testPlatformStaff(PlatformRole::SmsManager);
    $staff->givePermissionTo($queue->permission());

    return $staff;
}

function staffQueueUnrelatedStaff(): User
{
    return testPlatformStaff(PlatformRole::SmsManager);
}

it('tells the staff who hold the queue permission, and Super Admins, and nobody else', function (StaffQueue $queue) {
    $holder = staffQueueHolder($queue);
    $superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
    $unrelated = staffQueueUnrelatedStaff();

    // A partner can be handed the permission by mistake. They are a business
    // identity, not platform staff, and must still never be told.
    $partner = User::factory()->withBusinessAccount(fn ($account) => $account->active())->create();
    $partner->givePermissionTo($queue->permission());

    app(NotifyStaffOfSubmission::class)->handle($queue, 'subject-1', 'REF-1');

    expect($holder->notifications()->count())->toBe(1)
        ->and($superAdmin->notifications()->count())->toBe(1)
        ->and($unrelated->notifications()->count())->toBe(0)
        ->and($partner->notifications()->count())->toBe(0);
})->with(fn () => collect(StaffQueue::cases())->mapWithKeys(fn (StaffQueue $queue) => [$queue->value => [$queue]])->all());

it('does not tell a Supplier, who is not platform staff', function () {
    $supplier = Supplier::factory()->create();

    app(NotifyStaffOfSubmission::class)->handle(StaffQueue::SupplierKyc, 'subject-1', 'REF-1');

    expect($supplier->notifications()->count())->toBe(0);
});

it('maps every queue to the permission that opens the screen it links to', function () {
    expect(collect(StaffQueue::cases())->mapWithKeys(fn (StaffQueue $queue) => [$queue->value => $queue->permission()])->all())
        ->toBe([
            'kyc' => 'kyc.view',
            'supplier_kyc' => 'supplier.kyc.view',
            'supplier_listing' => 'supplier_listing.view',
            'supplier_listing_lot' => 'supplier_listing.view',
            'supplier_stock_update' => 'supplier_stock.view',
            'account_withdrawal' => 'withdrawal.view',
            'supplier_withdrawal' => 'withdrawal.view',
            'order_return' => 'order.view',
            'refund_request' => 'payment.view',
            'website_request' => 'website.view',
            // A request to be erased is shown only to someone who may erase.
            'account_deletion' => 'account.delete',
            'supplier_deletion' => 'supplier.delete',
        ]);
});

it('writes one bell entry however many times the same submission is announced', function () {
    $holder = staffQueueHolder(StaffQueue::AccountWithdrawal);
    $announce = app(NotifyStaffOfSubmission::class);

    $announce->handle(StaffQueue::AccountWithdrawal, 'withdrawal-1', 'WDR-1');
    $announce->handle(StaffQueue::AccountWithdrawal, 'withdrawal-1', 'WDR-1');

    expect($holder->notifications()->count())->toBe(1);

    // A different submission is a different notification.
    $announce->handle(StaffQueue::AccountWithdrawal, 'withdrawal-2', 'WDR-2');

    expect($holder->notifications()->count())->toBe(2);
});

it('does not write a second row when a queued notification is retried', function () {
    $holder = staffQueueHolder(StaffQueue::Kyc);
    $notification = new WorkItemSubmitted(StaffQueue::Kyc, 'KYC-ABCD1234');
    $notification->id = '5f0c8a8e-3f0f-4f4e-9b43-1c1c5c1c5c1c';

    Notification::sendNow($holder, $notification);
    Notification::sendNow($holder, $notification);

    expect($holder->notifications()->count())->toBe(1);
});

it('is in-app only, and stores a reference and nothing else', function () {
    $holder = staffQueueHolder(StaffQueue::SupplierWithdrawal);

    expect((new WorkItemSubmitted(StaffQueue::SupplierWithdrawal))->via($holder))->toBe(['database']);

    app(NotifyStaffOfSubmission::class)->handle(StaffQueue::SupplierWithdrawal, 'withdrawal-1', 'WDR-0001');

    // No name, no amount, no bank or payout detail, no business — only which
    // queue and which row.
    expect($holder->notifications()->sole()->data)->toBe([
        'event' => 'queue.supplier_withdrawal',
        'reference' => 'WDR-0001',
    ]);
});

it('derives a safe reference from the public id when a submission has none of its own', function () {
    $submission = new KycSubmission;
    $submission->setRawAttributes(['public_id' => '01jabcdefghjkmnpqrstvwxyz0', 'business_account_id' => 7]);

    // A short code and the tail of the public id: enough to match the row,
    // and never the business, a name or any other detail it carries.
    expect(StaffQueue::Kyc->referenceFor($submission))->toBe('KYC-STVWXYZ0');
});

it('prefers a reference the submission already has', function () {
    $withdrawal = new AccountWithdrawal;
    $withdrawal->setRawAttributes(['public_id' => '01jabcdefghjkmnpqrstvwxyz0', 'reference' => 'WDR-000123']);

    expect(StaffQueue::AccountWithdrawal->referenceFor($withdrawal))->toBe('WDR-000123');
});
it('shows the bell entry with its title, reference and the authorised review screen', function () {
    $holder = staffQueueHolder(StaffQueue::AccountWithdrawal);

    app(NotifyStaffOfSubmission::class)->handle(StaffQueue::AccountWithdrawal, 'withdrawal-1', 'WDR-0001');

    $this->actingAs($holder)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.0.title', 'New withdrawal request')
            ->where('notifications.0.description', 'A partner has requested a withdrawal. Reference WDR-0001')
            ->where('notifications.0.href', route('admin.account-withdrawals.index'))
            ->where('unreadNotificationCount', 1),
        );
});

it('links each queue to its own review screen', function () {
    expect(collect(StaffQueue::cases())->mapWithKeys(fn (StaffQueue $queue) => [$queue->value => $queue->route()])->all())
        ->toBe([
            'kyc' => route('admin.kyc.index'),
            'supplier_kyc' => route('admin.suppliers.index'),
            'supplier_listing' => route('admin.supplier-listings.index'),
            'supplier_listing_lot' => route('admin.supplier-listing-lots.index'),
            'supplier_stock_update' => route('admin.supplier-stock.index'),
            'account_withdrawal' => route('admin.account-withdrawals.index'),
            'supplier_withdrawal' => route('admin.supplier-withdrawals.index'),
            'order_return' => route('admin.returns.index'),
            'refund_request' => route('admin.payments.index'),
            'website_request' => route('admin.websites.index'),
            'account_deletion' => route('admin.deletions.index'),
            'supplier_deletion' => route('admin.deletions.index'),
        ]);
});

it('has English and Bangla wording for every queue', function () {
    foreach (['en', 'bn'] as $locale) {
        $events = trans('notification.events', [], $locale);

        foreach (StaffQueue::cases() as $queue) {
            expect($events[$queue->event()]['title'] ?? null)->toBeString()->not->toBe('')
                ->and($events[$queue->event()]['description'] ?? null)->toBeString()->not->toBe('');
        }
    }
});

/*
 * The wiring guard. Each of the ten actions must announce its own queue, after
 * the work has committed. Reading the source is blunt, but it is the one check
 * that fails the moment someone refactors an action and loses the call — the
 * behaviour tests only cover the actions that are cheap to run.
 */
it('has every submit and request action announce its queue', function (string $file, StaffQueue $queue) {
    $source = file_get_contents(base_path($file));

    expect($source)->toContain('StaffQueue::'.str($queue->name)->toString().'->announce(');
})->with([
    'partner KYC' => ['app/Domain/Kyc/Actions/SubmitKyc.php', StaffQueue::Kyc],
    'supplier KYC' => ['app/Domain/Supplier/Actions/SubmitSupplierKyc.php', StaffQueue::SupplierKyc],
    'supplier listing' => ['app/Domain/Supplier/Actions/SubmitSupplierListing.php', StaffQueue::SupplierListing],
    'supplier listing lot' => ['app/Domain/Supplier/Actions/SubmitSupplierListingLot.php', StaffQueue::SupplierListingLot],
    'supplier stock update' => ['app/Domain/Supplier/Actions/SubmitSupplierStockUpdate.php', StaffQueue::SupplierStockUpdate],
    'partner withdrawal' => ['app/Domain/Withdrawal/Actions/RequestAccountWithdrawal.php', StaffQueue::AccountWithdrawal],
    'supplier withdrawal' => ['app/Domain/Supplier/Actions/RequestSupplierWithdrawal.php', StaffQueue::SupplierWithdrawal],
    'return request' => ['app/Domain/Order/Actions/RequestOrderReturn.php', StaffQueue::OrderReturn],
    'refund request' => ['app/Domain/Billing/Actions/RequestRefund.php', StaffQueue::RefundRequest],
    'website request' => ['app/Domain/Website/Actions/RequestWebsite.php', StaffQueue::WebsiteRequest],
]);

it('tells the supplier reviewers when a supplier submits an availability update', function () {
    $supplier = Supplier::factory()->create();
    $offer = supplierTestOffer($supplier);
    $reviewer = staffQueueHolder(StaffQueue::SupplierStockUpdate);
    $unrelated = staffQueueUnrelatedStaff();

    $update = app(SubmitSupplierStockUpdate::class)->handle($supplier, $offer, 40, 'New batch');

    $notification = $reviewer->notifications()->sole();

    expect($notification->data['event'])->toBe('queue.supplier_stock_update')
        ->and($notification->data['reference'])->toBe(StaffQueue::SupplierStockUpdate->referenceFor($update))
        // The supplier's own note is not copied into the staff bell.
        ->and(json_encode($notification->data))->not->toContain('New batch')
        ->and($unrelated->notifications()->count())->toBe(0);
});
