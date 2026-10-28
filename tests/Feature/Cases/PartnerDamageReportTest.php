<?php

use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Enums\ReturnInitiator;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Models\Order;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * A Partner reports a damaged item from a delivered Store Order (D35).
 *
 * It creates a *reported* damage and nothing more: who bears the loss is decided
 * later by staff who have seen the goods, the loss is valued on the server, and an
 * item that stays with the customer has no parcel, no receipt and no delivery.
 */
beforeEach(function () {
    caseTestSetUp();

    $this->stranger = websiteTestAccount();
    $this->wallet = websiteTestWallet($this->account, '20000.00');
});

function damageReportBody(Order $order, array $over = []): array
{
    return [
        'item' => $order->items()->sole()->public_id,
        'quantity' => 1,
        'condition' => 'torn',
        'description' => 'The fabric tore along the seam on arrival.',
        'item_location' => 'send_back',
        'confirmed' => '1',
        'idempotency_key' => 'report-'.uniqid(),
        ...$over,
    ];
}

describe('the form', function () {
    it('offers only what can still be reported, in a delivered order, with what the form needs', function () {
        $order = caseTestDeliveredOrder();

        $this->actingAs($this->partner)->get(route('damages.create', $order->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('damages/create')
                ->where('can_report', true)
                ->where('eligibility.eligible', true)
                ->where('eligibility.lines.0.reportable', 3)
                ->where('eligibility.lines.0.supplier_backed', false)
                ->where('minimum_description', 10)
                ->has('conditions')
                ->has('evidence_limits'));
    });

    it('says why nothing can be reported once everything has been, and when the window has closed', function () {
        $order = caseTestDeliveredOrder();
        $report = fn () => $this->actingAs($this->partner)->get(route('damages.create', $order->public_id));

        $this->actingAs($this->partner)->post(route('damages.store', $order->public_id), damageReportBody($order, ['quantity' => 3]))->assertSessionHasNoErrors();

        $report()->assertInertia(fn (Assert $page) => $page->where('can_report', false)->where('eligibility.refusal', 'nothing_reportable'));

        $later = caseTestDeliveredOrder();
        $this->travel(400)->days();

        $this->actingAs($this->partner)->get(route('damages.create', $later->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('can_report', false)->where('eligibility.refusal', 'damage_window_closed'));
        $this->actingAs($this->partner)->post(route('damages.store', $later->public_id), damageReportBody($later))->assertSessionHasErrors('quantity');
    });

    it('is a 404 for another Partner\'s order, and reports nothing for them', function () {
        $order = caseTestDeliveredOrder();

        $this->actingAs($this->stranger->owner)->get(route('damages.create', $order->public_id))->assertNotFound();
        $this->actingAs($this->stranger->owner)->post(route('damages.store', $order->public_id), damageReportBody($order))->assertNotFound();

        expect(OrderDamage::query()->count())->toBe(0);
    });

    it('is offered on the Store Order for an eligible order, and not for a fully reported one', function () {
        $order = caseTestDeliveredOrder();

        $this->actingAs($this->partner)->get(route('store-orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.report_damage', true)
                ->where('urls.report_damage', route('damages.create', $order->public_id)));

        $this->actingAs($this->partner)->post(route('damages.store', $order->public_id), damageReportBody($order, ['quantity' => 3]));

        $this->actingAs($this->partner)->get(route('store-orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('can.report_damage', false));
    });
});

describe('reporting', function () {
    it('creates a reported damage that is the Partner\'s own to bear for now, and finalizes nothing', function () {
        $order = caseTestDeliveredOrder();

        $this->actingAs($this->partner)->post(route('damages.store', $order->public_id), damageReportBody($order, [
            // A Partner never says who is responsible, and never sends an amount.
            'responsible_party' => 'banij', 'loss' => '1.00', 'approved_loss' => '1.00', 'status' => 'finalized',
        ]))->assertSessionHasNoErrors();

        $damage = OrderDamage::query()->sole();

        expect($damage->initiated_by_type)->toBe(ReturnInitiator::Partner)
            ->and($damage->status)->toBe(DamageStatus::AwaitingReceipt)
            ->and($damage->responsible_party)->not->toBe(ReturnParty::Banij)
            ->and($damage->approved_loss)->toBeNull()
            // Valued on the server, from the line — never from anything the Partner sent.
            ->and($damage->max_recoverable_value->toDecimal())->toBe('2500.00');
    });

    it('sends an item that stays with the customer straight to inspection, with no parcel, no stock and no delivery', function () {
        $order = caseTestDeliveredOrder();
        $stock = $this->stock->refresh();

        $this->actingAs($this->partner)->post(route('damages.store', $order->public_id), damageReportBody($order, ['item_location' => 'stays_with_customer']))->assertSessionHasNoErrors();

        $damage = OrderDamage::query()->sole();

        expect($damage->parcel_expected)->toBeFalse()
            ->and($damage->in_custody)->toBeFalse()
            ->and($damage->status)->toBe(DamageStatus::UnderInspection)
            ->and($this->stock->refresh()->available)->toBe($stock->available);

        // No receipt is offered to staff, and the Partner has no delivery to ask for.
        $this->actingAs($this->inspector)->get(route('admin.cases.damages.show', $damage->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('case.remains_with_customer', true)
                ->where('actions', fn ($actions) => ! collect($actions)->pluck('key')->contains('receive_parcel')));

        $this->actingAs($this->partner)->get(route('damages.show', $damage->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('damage.remains_with_customer', true)->where('delivery.can_request', false));
    });

    it('is one report however many times it is sent, and attaches its evidence once', function () {
        $order = caseTestDeliveredOrder();
        $draft = $this->actingAs($this->partner)->post(route('cases.evidence.store'), ['order' => $order->public_id, 'file' => caseTestPng('torn.png')])->json('id');
        $body = damageReportBody($order, ['evidence' => [$draft], 'idempotency_key' => 'one-report']);

        $this->actingAs($this->partner)->post(route('damages.store', $order->public_id), $body);
        $this->actingAs($this->partner)->post(route('damages.store', $order->public_id), $body);

        $damage = OrderDamage::query()->sole();

        expect($damage->quantity)->toBe(1)
            ->and(CaseEvidence::query()->sole()->order_damage_id)->toBe($damage->id)
            ->and(CaseEvidence::query()->sole()->status)->toBe('submitted');
    });

    it('refuses too many units, a missing description and an unticked confirmation, and reports nothing', function () {
        $order = caseTestDeliveredOrder();
        $send = fn (array $over) => $this->actingAs($this->partner)->post(route('damages.store', $order->public_id), damageReportBody($order, $over));

        $send(['quantity' => 4])->assertSessionHasErrors('quantity');
        $send(['description' => 'too short'])->assertSessionHasErrors('description');
        $send(['confirmed' => null])->assertSessionHasErrors('confirmed');
        $send(['item_location' => 'somewhere'])->assertSessionHasErrors('item_location');
        $send(['condition' => 'wrecked'])->assertSessionHasErrors('condition');

        expect(OrderDamage::query()->count())->toBe(0);
    });

    it('reports nothing for someone who is not a member of the account', function () {
        $order = caseTestDeliveredOrder();
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->post(route('damages.store', $order->public_id), damageReportBody($order));

        expect($response->getStatusCode())->toBeIn([302, 403, 404])
            ->and(OrderDamage::query()->count())->toBe(0);
    });
});

describe('following it', function () {
    it('shows the Partner their own report while it is looked at, and tells staff about it', function () {
        $order = caseTestDeliveredOrder();
        $this->actingAs($this->partner)->post(route('damages.store', $order->public_id), damageReportBody($order));
        $damage = OrderDamage::query()->sole();

        $this->actingAs($this->partner)->get(route('damages.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('damages.data', 1)
                ->where('damages.data.0.reference', $damage->reference)
                ->where('damages.data.0.approved_loss', null)
                ->where('damages.data.0.status', 'awaiting_receipt'));

        $this->actingAs($this->partner)->get(route('damages.show', $damage->public_id))->assertOk();
        $this->actingAs($this->stranger->owner)->get(route('damages.show', $damage->public_id))->assertNotFound();

        // Staff see it in the unified queue with the step to take.
        $this->actingAs($this->inspector)->get(route('admin.cases.index', ['type' => 'damage']))
            ->assertInertia(fn (Assert $page) => $page->where('cases.data.0.reference', $damage->reference)->where('cases.data.0.next_action.key', 'receive_parcel'));
    });

    it('drops it from the Partner\'s list when staff decide Banij bears it, and keeps it when the Partner does', function () {
        $bears = caseTestDamagePosted(caseTestDeliveredOrder());
        $banij = caseTestDamagePosted(caseTestDeliveredOrder(), ReturnParty::Banij);

        $this->actingAs($this->partner)->get(route('damages.index'))
            ->assertInertia(fn (Assert $page) => $page->has('damages.data', 1)->where('damages.data.0.reference', $bears->reference));

        // …and a Banij-borne one is not reachable by its address either.
        $this->actingAs($this->partner)->get(route('damages.show', $banij->public_id))->assertNotFound();
    });
});

it('does not move any stock by reporting', function () {
    $order = caseTestDeliveredOrder();
    $before = $this->stock->refresh()->only(['available', 'sold', 'returned']);

    $this->actingAs($this->partner)->post(route('damages.store', $order->public_id), damageReportBody($order));

    expect($this->stock->refresh()->only(['available', 'sold', 'returned']))->toBe($before);
});
