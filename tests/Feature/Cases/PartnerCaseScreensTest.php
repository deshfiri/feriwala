<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Damage\Actions\DispatchDamage;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\ReturnCaseType;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * A Partner's Returns & Exchanges and My Damages (D35): self-scoped from the
 * signed-in account, server-calculated charges, private evidence, and a delivery
 * request that carries an address and never an amount.
 */
beforeEach(function () {
    caseTestSetUp();

    $this->stranger = websiteTestAccount();
    $this->strangerOwner = $this->stranger->owner;
});

describe('asking for a return or an exchange', function () {
    it('shows only what can still come back, and an estimate the server worked out', function () {
        $order = caseTestDeliveredOrder();

        $this->actingAs($this->partner)->get(route('cases.create', $order->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('cases/create')
                ->where('can_request', true)
                ->where('eligibility.eligible', true)
                ->where('eligibility.lines.0.returnable', 3)
                ->where('estimate.return_charge.amount', '40.00')
                ->where('estimate.original_delivery.amount', '80.00')
                ->where('estimate.estimated_total.amount', '40.00')
                ->has('reasons'));
    });

    it('is a 404 for another Partner\'s order', function () {
        $order = caseTestDeliveredOrder();

        $this->actingAs($this->strangerOwner)->get(route('cases.create', $order->public_id))->assertNotFound();
        $this->actingAs($this->strangerOwner)->post(route('cases.estimate', $order->public_id), ['type' => 'return', 'lines' => []])->assertNotFound();
        $this->actingAs($this->strangerOwner)->post(route('cases.store', $order->public_id), [])->assertNotFound();
    });

    it('works out the exchange charges on the server: the return charge, one new delivery, and never the original again', function () {
        $order = caseTestDeliveredOrder();
        $sku = $order->items()->sole()->sku;

        $this->actingAs($this->partner)->postJson(route('cases.estimate', $order->public_id), ['type' => 'exchange', 'lines' => [$sku => 2]])
            ->assertOk()
            ->assertJsonPath('return_charge.amount', '40.00')
            ->assertJsonPath('exchange_delivery.amount', '80.00')
            ->assertJsonPath('estimated_total.amount', '120.00')
            ->assertJsonPath('original_delivery.amount', '80.00');

        // A figure sent from the browser is never read.
        $this->actingAs($this->partner)->postJson(route('cases.estimate', $order->public_id), [
            'type' => 'return', 'lines' => [$sku => 2], 'return_charge' => '0.01', 'estimated_total' => '0.01',
        ])->assertOk()->assertJsonPath('estimated_total.amount', '40.00');

        // Asking for more than can come back is refused with the reason.
        $this->actingAs($this->partner)->postJson(route('cases.estimate', $order->public_id), ['type' => 'exchange', 'lines' => [$sku => 4]])
            ->assertStatus(422);
    });

    it('sends the request once, as the Partner\'s, with its attachments, however many times it is submitted', function () {
        $order = caseTestDeliveredOrder();
        $sku = $order->items()->sole()->sku;

        $draft = $this->actingAs($this->partner)->post(route('cases.evidence.store'), [
            'order' => $order->public_id, 'file' => caseTestPng('photo.png'),
        ])->assertCreated()->json('id');

        $payload = [
            'type' => 'exchange', 'reason' => 'not_as_described', 'description' => 'The size is wrong.',
            'lines' => [$sku => 2], 'evidence' => [$draft], 'confirmed' => '1', 'idempotency_key' => 'one-request',
            // Never read: a Partner does not say who is responsible.
            'responsible_party' => 'banij', 'return_charge' => '0.01',
        ];

        $first = $this->actingAs($this->partner)->post(route('cases.store', $order->public_id), $payload);
        $this->actingAs($this->partner)->post(route('cases.store', $order->public_id), $payload);

        $case = OrderReturn::query()->sole();

        $first->assertRedirect(route('cases.show', $case->public_id));

        expect($case->case_type)->toBe(ReturnCaseType::Exchange)
            ->and($case->responsible_party)->toBe(ReturnParty::Partner)
            ->and($case->initiated_by_type->value)->toBe('partner')
            ->and($case->return_charge->toDecimal())->toBe('40.00')
            ->and($case->exchange_delivery_charge->toDecimal())->toBe('80.00')
            ->and(Order::query()->where('source', OrderSource::Exchange)->count())->toBe(1)
            ->and(CaseEvidence::query()->sole()->status)->toBe('submitted')
            ->and(CaseEvidence::query()->sole()->order_return_id)->toBe($case->id);
    });

    it('refuses quantities that cannot come back, and an unticked confirmation, and makes no case', function () {
        $order = caseTestDeliveredOrder();
        $sku = $order->items()->sole()->sku;
        $ask = fn (array $over = []) => $this->actingAs($this->partner)->post(route('cases.store', $order->public_id), [
            'type' => 'return', 'reason' => 'not_as_described', 'lines' => [$sku => 2], 'confirmed' => '1',
            'idempotency_key' => 'k-'.uniqid(), ...$over,
        ]);

        $ask(['lines' => [$sku => 4]])->assertSessionHasErrors('lines');
        $ask(['confirmed' => null])->assertSessionHasErrors('confirmed');
        $ask(['reason' => 'because'])->assertSessionHasErrors('reason');

        expect(OrderReturn::query()->count())->toBe(0);

        // Two asked for, then two more: only one unit is left.
        $ask()->assertSessionHasNoErrors();
        $ask()->assertSessionHasErrors('lines');
        expect(OrderReturn::query()->count())->toBe(1);
    });

    it('is only for someone who manages the store', function () {
        $order = caseTestDeliveredOrder();
        $sku = $order->items()->sole()->sku;

        $viewer = websiteTestAccount()->owner;

        // Another account's member is a stranger; a member of this account without the right is refused.
        $this->actingAs($viewer)->post(route('cases.store', $order->public_id), ['type' => 'return', 'reason' => 'not_as_described', 'lines' => [$sku => 1], 'confirmed' => '1', 'idempotency_key' => 'x'])
            ->assertNotFound();
    });
});

describe('attachments before the request is sent', function () {
    it('keeps them private, refuses what is not a JPG, PNG, WebP or PDF, and lets the Partner take one back', function () {
        $order = caseTestDeliveredOrder();
        $upload = fn ($file) => $this->actingAs($this->partner)->post(route('cases.evidence.store'), ['order' => $order->public_id, 'file' => $file]);

        $upload(caseTestDisguisedScript())->assertStatus(422)->assertJsonPath('code', 'evidence_type_not_accepted');
        $upload(caseTestPdf())->assertCreated();

        $draft = $upload(caseTestPng())->assertCreated()->assertJsonPath('draft', true)->json();

        expect($draft['preview_url'])->toContain('signature=')
            ->and(CaseEvidence::query()->count())->toBe(2)
            ->and(CaseEvidence::query()->where('public_id', $draft['id'])->sole()->file->visibility->value)->toBe('private');

        $this->actingAs($this->partner)->deleteJson(route('cases.evidence.destroy', $draft['id']))->assertOk();

        expect(CaseEvidence::query()->count())->toBe(1);
    });

    it('is limited in size and in number', function () {
        $order = caseTestDeliveredOrder();
        $upload = fn ($file) => $this->actingAs($this->partner)->post(route('cases.evidence.store'), ['order' => $order->public_id, 'file' => $file]);

        config()->set('case_evidence.max_bytes', 10);
        $upload(caseTestPng())->assertStatus(422)->assertJsonPath('code', 'evidence_too_large');

        config()->set('case_evidence.max_bytes', 8 * 1024 * 1024);
        config()->set('case_evidence.max_per_case', 2);
        $upload(caseTestPng())->assertCreated();
        $upload(caseTestPng())->assertCreated();
        $upload(caseTestPng())->assertStatus(422)->assertJsonPath('code', 'evidence_too_many');
    });

    it('is never another Partner\'s to take, attach or remove, and attaching theirs refuses the whole request', function () {
        $order = caseTestDeliveredOrder();
        $sku = $order->items()->sole()->sku;
        $draft = $this->actingAs($this->partner)->post(route('cases.evidence.store'), ['order' => $order->public_id, 'file' => caseTestPng()])->json('id');

        $this->actingAs($this->strangerOwner)->deleteJson(route('cases.evidence.destroy', $draft))->assertForbidden();
        $this->actingAs($this->strangerOwner)->get(URL::temporarySignedRoute('cases.evidence.show', now()->addMinutes(5), ['evidence' => $draft]))->assertForbidden();

        // A colleague who is not the uploader cannot see a draft either; staff never see one.
        $this->actingAs($this->viewer)->get(URL::temporarySignedRoute('cases.evidence.show', now()->addMinutes(5), ['evidence' => $draft]))->assertForbidden();

        // Someone else's draft named in a request: the request is refused and no case exists.
        $other = caseTestDeliveredOrder();
        $this->actingAs($this->partner)->post(route('cases.store', $other->public_id), [
            'type' => 'return', 'reason' => 'not_as_described', 'lines' => [$other->items()->sole()->sku => 1],
            'evidence' => [$draft], 'confirmed' => '1', 'idempotency_key' => 'wrong-order',
        ])->assertSessionHasErrors('lines');

        expect(OrderReturn::query()->count())->toBe(0)
            ->and(CaseEvidence::query()->sole()->status)->toBe('draft')
            ->and($sku)->not->toBeEmpty();
    });

    it('is history once sent: it cannot be removed, and only the account and staff who may see the case can open it', function () {
        $order = caseTestDeliveredOrder();
        $draft = $this->actingAs($this->partner)->post(route('cases.evidence.store'), ['order' => $order->public_id, 'file' => caseTestPng()])->json('id');

        $this->actingAs($this->partner)->post(route('cases.store', $order->public_id), [
            'type' => 'return', 'reason' => 'not_as_described', 'lines' => [$order->items()->sole()->sku => 1],
            'evidence' => [$draft], 'confirmed' => '1', 'idempotency_key' => 'sent',
        ]);

        $case = OrderReturn::query()->sole();
        $link = fn () => URL::temporarySignedRoute('cases.evidence.show', now()->addMinutes(5), ['evidence' => $draft]);

        $this->actingAs($this->partner)->deleteJson(route('cases.evidence.destroy', $draft))->assertForbidden();

        // The Partner and staff who may see returns open it; another Partner and staff who may not, cannot.
        $this->actingAs($this->partner)->get($link())->assertOk();
        $this->actingAs($this->viewer)->get($link())->assertOk();
        $this->actingAs($this->strangerOwner)->get($link())->assertForbidden();
        $this->actingAs(testPlatformStaff(PlatformRole::CourierManager))->get($link())->assertForbidden();
        $this->actingAs($this->partner)->get(route('cases.evidence.show', $draft))->assertForbidden();

        // The Partner sees their evidence on their case and no storage path with it.
        $page = $this->actingAs($this->partner)->get(route('cases.show', $case->public_id))->viewData('page')['props'];
        expect($page['evidence'])->toHaveCount(1)
            ->and(json_encode($page))->not->toContain(CaseEvidence::query()->sole()->file->path);
    });
});

describe('following a return or an exchange', function () {
    it('lists only the Partner\'s own and a 404 for anyone else\'s', function () {
        $mine = caseTestOpenReturn(caseTestDeliveredOrder(), 1);

        $this->actingAs($this->partner)->get(route('cases.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('cases/index')
                ->has('cases.data', 1)
                ->where('cases.data.0.reference', $mine->reference)
                ->where('cases.data.0.status', 'requested')
                ->where('cases.data.0.status_label', __('cases.partner.status.requested')));

        $this->actingAs($this->strangerOwner)->get(route('cases.index'))->assertInertia(fn (Assert $page) => $page->has('cases.data', 0));
        $this->actingAs($this->strangerOwner)->get(route('cases.show', $mine->public_id))->assertNotFound();
        $this->actingAs($this->strangerOwner)->post(route('cases.cancel', $mine->public_id))->assertNotFound();
    });

    it('says what happens next in plain words, and what to do', function () {
        $case = caseTestOpenReturn(caseTestDeliveredOrder(), 2);
        $show = fn () => $this->actingAs($this->partner)->get(route('cases.show', $case->public_id));

        $show()->assertInertia(fn (Assert $page) => $page
            ->where('case.next_step.key', 'wait_for_review')
            ->where('case.can_cancel', true));

        caseTestAdvance($case, ReturnStatus::WaitingForParcel);

        $show()->assertInertia(fn (Assert $page) => $page
            ->where('case.status', 'send_back')
            ->where('case.next_step.action_needed', true));

        caseTestAdvance($case, ReturnStatus::InspectionApproved);

        $show()->assertInertia(fn (Assert $page) => $page
            ->where('case.status', 'settling')
            ->where('case.can_cancel', false)
            ->where('case.charge.amount', '40.00')
            ->where('case.charge_is_final', false));
    });

    it('shows the Partner only their own money, and that Banij covered what Banij bears', function () {
        websiteTestWallet($this->account, '1000.00');
        $case = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 2), ReturnStatus::InspectionApproved);

        $this->actingAs($this->viewer)->post(route('admin.cases.returns.settlement', $case->public_id));
        $this->actingAs($this->partner)->get(route('cases.show', $case->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('financials.totals.deducted.amount', '40.00')
                ->where('financials.totals.banij_cost', null)
                ->where('financials.ledger.0.ledger_url', fn ($url) => str_contains($url, '/wallet/transactions/')));

        $this->actingAs($this->orderManager)->post(route('admin.cases.returns.responsibility', $case->public_id), [
            'party' => 'banij', 'reason' => 'Packed badly at the warehouse: the fault is ours.',
        ]);

        $page = $this->actingAs($this->partner)->get(route('cases.show', $case->public_id));
        $json = json_encode(Arr::only($page->viewData('page')['props'], ['case', 'items', 'evidence', 'financials', 'timeline', 'refund']));

        $page->assertInertia(fn (Assert $page) => $page
            ->where('case.charge', null)
            ->where('case.charge_covered_by_banij', true)
            ->where('financials.totals.net_partner.amount', '0.00')
            ->where('financials.totals.banij_cost', null));

        // Nothing of Banij's own accounting, its reasons, its warehouse or who did it.
        foreach (['Packed badly', 'warehouse', $this->viewer->name, 'banij_cost":{'] as $leak) {
            expect($json)->not->toContain($leak);
        }
    });

    it('lets the Partner withdraw a request that has not been acted on, once goods are back it is not theirs to withdraw', function () {
        $case = caseTestOpenReturn(caseTestDeliveredOrder(), 1);

        $this->actingAs($this->partner)->post(route('cases.cancel', $case->public_id))->assertSessionHasNoErrors();
        expect($case->refresh()->status)->toBe(ReturnStatus::Cancelled);

        $second = caseTestAdvance(caseTestOpenReturn(caseTestDeliveredOrder(), 1), ReturnStatus::ParcelReceived);

        $this->actingAs($this->partner)->post(route('cases.cancel', $second->public_id))->assertSessionHasErrors('case');
        expect($second->refresh()->status)->toBe(ReturnStatus::ParcelReceived);
    });

    it('shows a store order its own cases, and who may ask for another', function () {
        $order = caseTestDeliveredOrder();
        $case = caseTestOpenReturn($order, 1);

        $this->actingAs($this->partner)->get(route('store-orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('cases.0.reference', $case->reference)
                ->where('can.request_case', true)
                ->where('urls.request_case', route('cases.create', $order->public_id)));
    });

    it('opens the exchange order a case sent, from the same store', function () {
        $order = caseTestDeliveredOrder();
        $case = caseTestOpenReturn($order, 1, ReturnCaseType::Exchange);

        $this->actingAs($this->partner)->get(route('store-orders.show', $case->exchangeOrder->public_id))->assertOk();
        $this->actingAs($this->strangerOwner)->get(route('store-orders.show', $case->exchangeOrder->public_id))->assertNotFound();
    });
});

describe('navigation', function () {
    it('shares no staff case permission with a Partner, so only their own two doors can show', function () {
        $this->actingAs($this->partner)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('permissions', fn ($permissions) => collect($permissions)->only(['return.view', 'exchange.view', 'damage.view'])->every(fn ($allowed) => $allowed === false)));
    });
});

describe('My Damages', function () {
    it('lists the items the Partner bears the loss for, and not Banij\'s', function () {
        websiteTestWallet($this->account, '10000.00');
        $mine = caseTestDamagePosted(caseTestDeliveredOrder());
        caseTestDamagePosted(caseTestDeliveredOrder(), ReturnParty::Banij);

        $this->actingAs($this->partner)->get(route('damages.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('damages/index')
                ->has('damages.data', 1)
                ->where('damages.data.0.reference', $mine->reference)
                ->where('damages.data.0.approved_loss.amount', '5000.00')
                ->where('damages.data.0.responsibility', 'partner')
                ->where('damages.data.0.storage_state', 'held_by_banij')
                ->where('damages.data.0.delivery_state', 'available')
                ->where('damages.data.0.product.name', $mine->product_name));

        $this->actingAs($this->strangerOwner)->get(route('damages.index'))->assertInertia(fn (Assert $page) => $page->has('damages.data', 0));
    });

    it('is a 404 for another Partner, for the page and for every action', function () {
        websiteTestWallet($this->account, '10000.00');
        $mine = caseTestDamagePosted(caseTestDeliveredOrder());
        $address = addressTestCreate(AddressOwnerType::BusinessAccount, $this->stranger->id);

        $this->actingAs($this->strangerOwner)->get(route('damages.show', $mine->public_id))->assertNotFound();
        $this->actingAs($this->strangerOwner)->post(route('damages.delivery.store', $mine->public_id), ['address' => $address->public_id, 'confirmed' => '1'])->assertNotFound();
        $this->actingAs($this->strangerOwner)->post(route('damages.receipt.store', $mine->public_id))->assertNotFound();
    });

    it('shows safe detail: the timeline, the evidence, the deduction and the delivery quote, and nothing internal', function () {
        websiteTestWallet($this->account, '10000.00');
        $damage = caseTestDamagePosted(caseTestDeliveredOrder());
        addressTestCreate(AddressOwnerType::BusinessAccount, $this->account->id);

        $response = $this->actingAs($this->partner)->get(route('damages.show', $damage->public_id));
        $json = json_encode(Arr::only($response->viewData('page')['props'], ['damage', 'evidence', 'financials', 'timeline', 'delivery']));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('damages/show')
            ->where('damage.reference', $damage->reference)
            ->where('damage.storage_state', 'held_by_banij')
            ->where('delivery.can_request', true)
            ->where('delivery.quote.charge.amount', '80.00')
            ->where('delivery.quote.wallet_can_cover', true)
            ->has('delivery.addresses', 1)
            ->where('financials.totals.deducted.amount', '5000.00')
            ->has('timeline'));

        foreach (['Ayesha Rahman', '+88017', 'House 12', 'Seam torn through', 'internal_note', $this->viewer->name, $this->inspector->name] as $leak) {
            expect($json)->not->toContain($leak);
        }
    });

    it('takes the delivery charge from the server, to one of the Partner\'s own addresses, once', function () {
        $wallet = websiteTestWallet($this->account, '10000.00');
        $damage = caseTestDamagePosted(caseTestDeliveredOrder());
        $address = addressTestCreate(AddressOwnerType::BusinessAccount, $this->account->id);

        $post = fn (array $body) => $this->actingAs($this->partner)->post(route('damages.delivery.store', $damage->public_id), $body);

        // A sent amount is never read; the address and the confirmation are all there is.
        $post(['address' => $address->public_id, 'confirmed' => '1', 'delivery_charge' => '0.01', 'amount' => '0.01'])->assertSessionHasNoErrors();
        $post(['address' => $address->public_id, 'confirmed' => '1'])->assertSessionHasNoErrors();

        $dispatch = $damage->refresh()->dispatch;

        expect($dispatch->delivery_charge->toDecimal())->toBe('80.00')
            ->and($wallet->refresh()->total->toDecimal())->toBe('4920.00')
            ->and(LedgerEntry::query()->where('type', LedgerTransactionType::DamageDeliveryDebit->value)->count())->toBe(1)
            ->and($damage->status)->toBe(DamageStatus::DispatchRequested);

        // Then dispatched, tracked and received.
        app(DispatchDamage::class)->handle($this->inspector, $damage, 'TRK-1001');

        $this->actingAs($this->partner)->get(route('damages.show', $damage->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('damage.tracking.number', 'TRK-1001')
                ->where('damage.delivery_request.state', 'dispatched')
                ->where('damage.storage_state', 'in_transit')
                ->where('can_confirm_receipt', true));

        $this->actingAs($this->partner)->post(route('damages.receipt.store', $damage->public_id))->assertSessionHasNoErrors();

        expect($damage->refresh()->status)->toBe(DamageStatus::ReceivedByPartner);
    });

    it('explains each refusal: no balance, someone else\'s address, nothing to send, already asked', function () {
        $damage = caseTestDamagePosted(caseTestDeliveredOrder());
        $wallet = websiteTestWallet($this->account, '10.00');
        $mine = addressTestCreate(AddressOwnerType::BusinessAccount, $this->account->id);
        $theirs = addressTestCreate(AddressOwnerType::BusinessAccount, $this->stranger->id);

        $post = fn (string $address) => $this->actingAs($this->partner)->post(route('damages.delivery.store', $damage->public_id), ['address' => $address, 'confirmed' => '1']);

        // The loss was posted against a wallet that could not cover it, and so is outstanding; the delivery is not.
        $post($mine->public_id)->assertSessionHasErrors(['address' => __('cases.errors.damage_delivery_balance_too_low')]);
        $post($theirs->public_id)->assertSessionHasErrors(['address' => __('cases.errors.damage_address_not_allowed')]);
        $this->actingAs($this->partner)->post(route('damages.delivery.store', $damage->public_id), ['address' => $mine->public_id])->assertSessionHasErrors('confirmed');

        expect($damage->refresh()->dispatch)->toBeNull()
            ->and($wallet->refresh()->total->toDecimal())->toBe('10.00');

        // Banij's own damage is not the Partner's to ask for.
        websiteTestWallet($this->stranger, '10000.00');
    });

    it('does not offer delivery for a product that stays with the customer, or for Banij\'s damage', function () {
        websiteTestWallet($this->account, '10000.00');
        $banij = caseTestDamagePosted(caseTestDeliveredOrder(), ReturnParty::Banij);

        // Not in the list, and not reachable by guessing its address.
        $this->actingAs($this->partner)->get(route('damages.show', $banij->public_id))->assertNotFound();
    });
});
