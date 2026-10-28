<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Cases\Actions\AttachDraftEvidence;
use App\Domain\Cases\Actions\StoreCaseEvidence;
use App\Domain\Damage\Actions\ChangeDamageResponsibility;
use App\Domain\Damage\Actions\DispatchDamage;
use App\Domain\Damage\Actions\ReportDamage;
use App\Domain\Damage\Actions\RequestDamageDelivery;
use App\Domain\Damage\Data\DamageReport;
use App\Domain\Damage\Enums\DamageCondition;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Notification\Actions\AnnounceCaseEvent;
use App\Domain\Notification\Events\NotificationsChanged;
use App\Domain\Notification\Queries\RecentNotifications;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnCaseType;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\Order;
use App\Models\User;
use App\Notifications\Cases\CaseOutcome;
use App\Notifications\Staff\CaseStepRequired;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;

/*
 * What staff and Partners are told about returns, exchanges and damages (D35).
 *
 * Staff hear that a step is waiting for them, and only staff who hold the
 * permission to take it (plus Super Admins); a Partner hears what happened to
 * their own case, and nobody else's. Each notification is written once however
 * often it is announced, carries nothing personal, opens only a page its reader
 * may open, and is worded in the reader's language.
 */
beforeEach(function () {
    caseTestSetUp();

    $this->stranger = websiteTestAccount();
    $this->superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->courier = testPlatformStaff(PlatformRole::CourierManager);
    $this->wallet = websiteTestWallet($this->account, '30000.00');
});

/** @return list<string> the events a person has been told, oldest first */
function caseNotificationEvents(User $user): array
{
    return $user->notifications()->orderBy('created_at')->orderBy('id')->get()->map(fn ($n) => $n->data['event'])->values()->all();
}

function caseNotificationFor(User $user, string $event): ?DatabaseNotification
{
    return $user->notifications()->get()->first(fn ($n) => $n->data['event'] === $event);
}

describe('staff are told when work arrives', function () {
    it('tells whoever may see returns, and Super Admins, and nobody else, when a return is asked for', function () {
        $partnerWithThePermission = $this->partner;
        $partnerWithThePermission->givePermissionTo('return.view');

        caseTestOpenReturn(caseTestDeliveredOrder(), 1);

        expect(caseNotificationEvents($this->orderManager))->toContain('queue.order_return')
            ->and(caseNotificationEvents($this->viewer))->toContain('queue.order_return')
            ->and(caseNotificationEvents($this->superAdmin))->toContain('queue.order_return')
            ->and(caseNotificationEvents($this->courier))->not->toContain('queue.order_return')
            // A Partner is a business identity: never told what is waiting in the platform's queues.
            ->and(caseNotificationEvents($partnerWithThePermission))->not->toContain('queue.order_return');
    });

    it('tells those who may see exchanges about an exchange, and not those who may only see returns', function () {
        $returnsOnly = caseTestStaffWith(['return.view']);
        $exchanges = caseTestStaffWith(['exchange.view']);

        caseTestOpenReturn(caseTestDeliveredOrder(), 1, ReturnCaseType::Exchange);

        expect(caseNotificationEvents($exchanges))->toBe(['queue.exchange_case'])
            ->and(caseNotificationEvents($returnsOnly))->toBe([]);
    });

    it('tells those who may see damages when a Partner reports one, and not when staff open it themselves', function () {
        $watcher = caseTestStaffWith(['damage.view']);
        $order = caseTestDeliveredOrder();

        app(ReportDamage::class)->fromDelivered(
            $this->partner, $order, $order->items()->sole()->public_id,
            new DamageReport(1, DamageCondition::Torn, 'Arrived with the seam torn.', parcelExpected: true),
        );

        expect(caseNotificationEvents($watcher))->toBe(['queue.damage_case']);

        $staffReport = app(ReportDamage::class)->fromDelivered(
            $this->inspector, $second = caseTestDeliveredOrder(), $second->items()->sole()->public_id,
            new DamageReport(1, DamageCondition::Torn, 'Seen torn at the warehouse.', parcelExpected: false),
        );

        expect($staffReport)->toBeInstanceOf(OrderDamage::class)
            ->and(caseNotificationEvents($watcher))->toBe(['queue.damage_case']);
    });

    it('tells the staff who may inspect when the parcel is received, and the Partner', function () {
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::ParcelReceived);

        $step = caseNotificationFor($this->orderManager, 'case.step.inspect');

        expect($step)->not->toBeNull()
            ->and($step->data)->toBe(['event' => 'case.step.inspect', 'kind' => 'return', 'reference' => $case->reference, 'case' => $case->public_id])
            ->and(caseNotificationEvents($this->superAdmin))->toContain('case.step.inspect')
            // Finance may see returns but cannot inspect goods: not told to.
            ->and(caseNotificationEvents($this->viewer))->not->toContain('case.step.inspect')
            ->and(caseNotificationEvents($this->courier))->not->toContain('case.step.inspect')
            ->and(caseNotificationEvents($this->partner))->toContain('case.outcome.parcel_received');
    });

    it('asks for each damage step from the people who hold it: responsibility, posting, delivery', function () {
        $decider = caseTestStaffWith(['damage.view', 'damage.assign_liability']);
        $poster = caseTestStaffWith(['damage.view', 'damage.finalize']);
        $sender = caseTestStaffWith(['damage.view', 'damage.dispatch']);

        $damage = caseTestDamagePosted(caseTestDeliveredOrder());
        $address = addressTestCreate(AddressOwnerType::BusinessAccount, $this->account->id);
        app(RequestDamageDelivery::class)->handle($this->partner, $damage, $address->public_id);

        $steps = fn (User $user) => array_values(array_filter(caseNotificationEvents($user), fn (string $event) => str_starts_with($event, 'case.step.')));

        // Each person is asked only for the step their own permission lets them take.
        expect($steps($decider))->toBe(['case.step.assign_liability'])
            ->and($steps($poster))->toBe(['case.step.finalize'])
            ->and($steps($sender))->toBe(['case.step.dispatch'])
            ->and(caseNotificationEvents($this->superAdmin))->toContain('case.step.assign_liability', 'case.step.finalize', 'case.step.dispatch');
    });
});

describe('a Partner is told what happened to their own case', function () {
    it('says each outcome of a return once, and nothing to another Partner', function () {
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::InspectionApproved);

        $this->actingAs($this->viewer)->post(route('admin.cases.returns.settlement', $case->public_id))->assertSessionHasNoErrors();

        $told = caseNotificationEvents($this->partner);

        expect($told)->toContain('decision.return.approved', 'case.outcome.parcel_received', 'case.outcome.inspection_completed', 'case.outcome.responsibility_finalized', 'case.outcome.charge_posted')
            ->and(array_count_values($told)['case.outcome.inspection_completed'])->toBe(1)
            ->and($this->stranger->owner->notifications()->count())->toBe(0);
    });

    it('tells a Partner a refund was posted, and says a charge only when the Partner bears it', function () {
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::InspectionApproved);

        $case->responsible_party = ReturnParty::Banij;
        app(AnnounceCaseEvent::class)->returnMoved($case, ReturnStatus::SettlementPending, OrderStatusChangeSource::Staff);

        expect(caseNotificationEvents($this->partner))->toContain('case.outcome.responsibility_finalized')
            ->not->toContain('case.outcome.charge_posted');

        $case->refund_state = ReturnRefundState::Completed;
        app(AnnounceCaseEvent::class)->returnMoved($case, ReturnStatus::Completed, OrderStatusChangeSource::System);

        expect(caseNotificationEvents($this->partner))->toContain('case.outcome.refund_posted');
    });

    it('says a staff cancellation, but not the Partner\'s own', function () {
        $byPartner = caseTestOpenReturn(caseTestDeliveredOrder(), 1);
        $this->actingAs($this->partner)->post(route('cases.cancel', $byPartner->public_id));

        expect(caseNotificationEvents($this->partner))->not->toContain('case.outcome.request_cancelled_by_staff');

        $byStaff = caseTestOpenReturn(caseTestDeliveredOrder(), 1);
        $byStaff->moveTo(ReturnStatus::Cancelled, new StatusChange(actorId: $this->orderManager->id, reason: 'Cancelled by staff.'), OrderStatusChangeSource::Staff);

        expect(caseNotificationEvents($this->partner))->toContain('case.outcome.request_cancelled_by_staff');
    });

    it('says an exchange\'s replacement was created, and again when it was sent', function () {
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 1, ReturnCaseType::Exchange), ReturnStatus::InspectionApproved);

        expect(caseNotificationEvents($this->partner))->toContain('case.outcome.exchange_order_created');

        $replacement = Order::query()->findOrFail($case->refresh()->exchange_order_id);

        foreach ([OrderStatus::ReadyForFulfillment, OrderStatus::Picking, OrderStatus::Packing, OrderStatus::ReadyForPickup, OrderStatus::CourierAssigned, OrderStatus::Shipped] as $status) {
            $replacement->moveTo($status, new StatusChange(reason: 'Moved by the test.'), OrderStatusChangeSource::System);
        }

        expect(caseNotificationEvents($this->partner))->toContain('case.outcome.exchange_dispatched');
    });

    it('says a damage was finalized, that the delivery was accepted and sent, and that who bears it was corrected', function () {
        $damage = caseTestDamagePosted(caseTestDeliveredOrder());
        $address = addressTestCreate(AddressOwnerType::BusinessAccount, $this->account->id);
        app(RequestDamageDelivery::class)->handle($this->partner, $damage, $address->public_id);
        app(DispatchDamage::class)->handle($this->inspector, $damage, 'TRK-1');

        expect(caseNotificationEvents($this->partner))->toContain(
            'case.outcome.responsibility_finalized', 'case.outcome.damage_finalized', 'case.outcome.charge_posted',
            'case.outcome.delivery_accepted', 'case.outcome.damage_dispatched',
        );

        $other = caseTestDamagePosted(caseTestDeliveredOrder());
        app(ChangeDamageResponsibility::class)->handle($this->viewer, $other, ReturnParty::Banij, 'Packed badly at the warehouse.');

        expect(caseNotificationEvents($this->partner))->toContain('case.outcome.responsibility_corrected');
    });

    it('says an item marked received only when staff marked it', function () {
        $damage = caseTestDamagePosted(caseTestDeliveredOrder());
        $address = addressTestCreate(AddressOwnerType::BusinessAccount, $this->account->id);
        app(RequestDamageDelivery::class)->handle($this->partner, $damage, $address->public_id);
        app(DispatchDamage::class)->handle($this->inspector, $damage, 'TRK-2');

        $this->actingAs($this->partner)->post(route('damages.receipt.store', $damage->public_id))->assertSessionHasNoErrors();

        expect(caseNotificationEvents($this->partner))->not->toContain('case.outcome.damage_received');

        $second = caseTestDamagePosted(caseTestDeliveredOrder());
        app(RequestDamageDelivery::class)->handle($this->partner, $second, $address->public_id);
        app(DispatchDamage::class)->handle($this->inspector, $second, 'TRK-3');
        $this->actingAs($this->inspector)->post(route('admin.cases.damages.receipt', $second->public_id))->assertSessionHasNoErrors();

        expect(caseNotificationEvents($this->partner))->toContain('case.outcome.damage_received');
    });
});

describe('every notification is safe and written once', function () {
    it('writes one entry however often the same step is announced', function () {
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::ParcelReceived);

        $before = [$this->orderManager->notifications()->count(), $this->partner->notifications()->count()];

        foreach (range(1, 3) as $_) {
            app(AnnounceCaseEvent::class)->returnMoved($case->refresh(), ReturnStatus::ParcelReceived, OrderStatusChangeSource::Staff);
        }

        expect([$this->orderManager->notifications()->count(), $this->partner->notifications()->count()])->toBe($before);
    });

    it('carries a reference, a kind and a public id, and nothing about the customer, the evidence or a staff note', function () {
        $case = caseTestOpenReturn(caseTestDeliveredOrder(), 1);
        $evidence = app(StoreCaseEvidence::class)->draft($this->partner, $case->order, caseTestPng('seam-left-sleeve.png'), 'Private note about Ayesha.');
        app(AttachDraftEvidence::class)->handle($this->partner, $case, [$evidence->public_id]);
        caseTestAdvance($case, ReturnStatus::InspectionApproved);

        $stored = json_encode([
            ...$this->partner->notifications()->get()->map->data->all(),
            ...$this->orderManager->notifications()->get()->map->data->all(),
        ]);

        foreach (['Ayesha', '+88017', 'House 12', 'seam-left-sleeve', 'Private note', 'The goods are as described', 'case-evidence/'] as $leak) {
            expect($stored)->not->toContain($leak);
        }

        foreach ($this->partner->notifications()->get() as $notification) {
            expect(array_keys($notification->data))->toBeIn([['event', 'kind', 'reference', 'case'], ['event', 'reference', 'website', 'order', 'case'], ['event', 'reference'], ['event', 'kind', 'reference', 'case', 'website', 'order']]);
        }
    });

    it('is in-app only', function () {
        expect((new CaseStepRequired('inspect', 'return', 'RET-1', 'x'))->via($this->partner))->toBe(['database'])
            ->and((new CaseOutcome('parcel_received', 'return', 'RET-1', 'x'))->via($this->partner))->toBe(['database']);
    });
});

describe('the bell', function () {
    it('opens the workspace for staff, only while they may, and the Partner\'s own page for a Partner', function () {
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::ParcelReceived);

        $staff = collect(app(RecentNotifications::class)->forUser($this->orderManager))->firstWhere('title', __('notification.events')['case.step.inspect']['title']);
        $partner = collect(app(RecentNotifications::class)->forUser($this->partner))->firstWhere('title', __('notification.events')['case.outcome.parcel_received']['title']);

        expect($staff->href)->toBe(route('admin.cases.returns.show', $case->public_id))
            ->and($partner->href)->toBe(route('cases.show', $case->public_id));

        // A permission taken away takes the link with it.
        $this->orderManager->revokePermissionTo('return.view');
        $this->orderManager->roles()->detach();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $again = collect(app(RecentNotifications::class)->forUser($this->orderManager->refresh()))->firstWhere('title', __('notification.events')['case.step.inspect']['title']);

        expect($again->href)->toBeNull();
    });

    it('never opens another Partner\'s case, nor a damage that is Banij\'s to bear', function () {
        $case = caseTestOpenReturn(caseTestDeliveredOrder(), 1);

        $this->stranger->owner->notify(new CaseOutcome('parcel_received', 'return', $case->reference, $case->public_id));

        $banij = caseTestDamagePosted(caseTestDeliveredOrder(), ReturnParty::Banij);

        $this->partner->notify(new CaseOutcome('damage_finalized', 'damage', $banij->reference, $banij->public_id));

        $strangersBell = app(RecentNotifications::class)->forUser($this->stranger->owner);
        $partnersBell = collect(app(RecentNotifications::class)->forUser($this->partner))
            ->firstWhere('title', __('notification.events')['case.outcome.damage_finalized']['title']);

        expect($strangersBell[0]->href)->toBeNull()
            ->and($partnersBell->href)->toBeNull();
    });

    it('is worded in English and in Bangla', function () {
        caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::ParcelReceived);
        $bell = fn () => collect(app(RecentNotifications::class)->forUser($this->partner))->pluck('title')->all();

        app()->setLocale('en');
        expect($bell())->toContain('We received your parcel');

        app()->setLocale('bn');
        $bangla = $bell();

        expect($bangla)->toContain('আমরা আপনার পার্সেল পেয়েছি');

        foreach ($bangla as $title) {
            expect($title)->toMatch('/\p{Bengali}/u')->and($title)->not->toContain('case.outcome');
        }
    });

    it('has wording in both languages for every event it can be given', function () {
        foreach (['en', 'bn'] as $locale) {
            $events = trans('notification.events', [], $locale);

            foreach (['case.step.inspect', 'case.step.assign_liability', 'case.step.finalize', 'case.step.dispatch', 'queue.exchange_case', 'queue.damage_case',
                'case.outcome.request_cancelled_by_staff', 'case.outcome.parcel_received', 'case.outcome.inspection_completed', 'case.outcome.responsibility_finalized',
                'case.outcome.responsibility_corrected', 'case.outcome.charge_posted', 'case.outcome.refund_posted', 'case.outcome.exchange_order_created',
                'case.outcome.exchange_dispatched', 'case.outcome.damage_finalized', 'case.outcome.delivery_accepted', 'case.outcome.damage_dispatched',
                'case.outcome.damage_received', 'case.outcome.request_rejected'] as $event) {
                expect($events[$event]['title'] ?? null)->toBeString()->not->toBe('')
                    ->and($events[$event]['description'] ?? null)->toBeString()->not->toBe('');
            }
        }
    });
});

describe('live delivery', function () {
    it('announces a case notification on its recipient\'s own channel', function () {
        Event::fake([NotificationsChanged::class]);

        caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::ParcelReceived);

        Event::assertDispatched(NotificationsChanged::class, fn (NotificationsChanged $event) => $event->channelName === 'notifications.user.'.$this->partner->public_id && $event->reason === NotificationsChanged::RECEIVED);
        Event::assertDispatched(NotificationsChanged::class, fn (NotificationsChanged $event) => $event->channelName === 'notifications.user.'.$this->orderManager->public_id);
    });

    it('keeps a Partner off a staff member\'s channel, and staff off a Partner\'s', function () {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        Broadcast::purge();
        require base_path('routes/channels.php');

        $join = fn (User $who, User $channelOwner) => $this->actingAs($who)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-notifications.user.'.$channelOwner->public_id,
        ]);

        $join($this->partner, $this->partner)->assertOk();
        $join($this->orderManager, $this->orderManager)->assertOk();
        $join($this->partner, $this->orderManager)->assertForbidden();
        $join($this->orderManager, $this->partner)->assertForbidden();
        $join($this->stranger->owner, $this->partner)->assertForbidden();
    });
});
