<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Enums\ReturnCaseType;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use Laravel\Sanctum\Sanctum;

/*
 * A Partner's Returns & Exchanges and My Damages on the mobile API (D35): the same
 * actions, figures and self-scoping as the web screens, with evidence opened by
 * the bearer token instead of a browser session.
 */
beforeEach(function () {
    caseTestSetUp();

    $this->stranger = websiteTestAccount();
});

function mobileCasesDamageBody(Order $order, array $over = []): array
{
    return [
        'item' => $order->items()->sole()->public_id,
        'quantity' => 1,
        'condition' => 'torn',
        'description' => 'The fabric tore along the seam on arrival.',
        'item_location' => 'send_back',
        'confirmed' => true,
        'idempotency_key' => 'report-'.uniqid(),
        ...$over,
    ];
}

describe('my damages', function () {
    it('reports a damaged item once with its evidence, then lists and shows it', function () {
        $order = caseTestDeliveredOrder();
        Sanctum::actingAs($this->partner);

        $this->getJson(route('mobile.v1.damages.create', $order->public_id))
            ->assertOk()
            ->assertJsonPath('can_report', true)
            ->assertJsonPath('eligibility.lines.0.reportable', 3)
            ->assertJsonPath('minimum_description', 10);

        $draft = $this->post(route('mobile.v1.cases.evidence.store'), ['order' => $order->public_id, 'file' => caseTestPng('torn.png')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('id');

        $body = mobileCasesDamageBody($order, ['evidence' => [$draft], 'idempotency_key' => 'one-report', 'responsible_party' => 'banij']);

        $first = $this->postJson(route('mobile.v1.damages.store', $order->public_id), $body)
            ->assertCreated()
            ->assertJsonPath('damage.status', 'awaiting_receipt')
            ->assertJsonPath('damage.order_id', $order->public_id)
            ->assertJsonCount(1, 'evidence');
        $this->postJson(route('mobile.v1.damages.store', $order->public_id), $body)->assertCreated();

        $damage = OrderDamage::query()->sole();

        expect($first->json('damage.id'))->toBe($damage->public_id)
            ->and($damage->responsible_party)->not->toBe(ReturnParty::Banij)
            ->and(CaseEvidence::query()->sole()->order_damage_id)->toBe($damage->id);

        $this->getJson(route('mobile.v1.damages.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $damage->public_id)
            ->assertJsonPath('meta.total', 1);

        $this->getJson(route('mobile.v1.damages.show', $damage->public_id))
            ->assertOk()
            ->assertJsonPath('damage.reference', $damage->reference)
            ->assertJsonStructure(['delivery' => ['can_request', 'quote', 'addresses'], 'financials', 'timeline', 'can_confirm_receipt']);
    });

    it('refuses an invalid report with 422 and reports nothing', function () {
        $order = caseTestDeliveredOrder();
        Sanctum::actingAs($this->partner);

        $this->postJson(route('mobile.v1.damages.store', $order->public_id), mobileCasesDamageBody($order, ['quantity' => 4]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
        $this->postJson(route('mobile.v1.damages.store', $order->public_id), mobileCasesDamageBody($order, ['confirmed' => false]))
            ->assertJsonValidationErrors('confirmed');

        expect(OrderDamage::query()->count())->toBe(0);
    });

    it('is a 404 for another Partner, and keeps a Banij-borne damage out of the list', function () {
        $order = caseTestDeliveredOrder();
        $mine = caseTestDamagePosted(caseTestDeliveredOrder());
        $banij = caseTestDamagePosted(caseTestDeliveredOrder(), ReturnParty::Banij);

        Sanctum::actingAs($this->stranger->owner);

        $this->getJson(route('mobile.v1.damages.create', $order->public_id))->assertNotFound();
        $this->postJson(route('mobile.v1.damages.store', $order->public_id), mobileCasesDamageBody($order))->assertNotFound();
        $this->getJson(route('mobile.v1.damages.show', $mine->public_id))->assertNotFound();
        $this->postJson(route('mobile.v1.damages.receipt.store', $mine->public_id))->assertNotFound();
        $this->getJson(route('mobile.v1.damages.index'))->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($this->partner);

        $this->getJson(route('mobile.v1.damages.index'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->public_id);
        $this->getJson(route('mobile.v1.damages.show', $banij->public_id))->assertNotFound();
    });
});

describe('returns and exchanges', function () {
    it('gives the form, the server\'s estimate, and makes one case however often it is sent', function () {
        $order = caseTestDeliveredOrder();
        $sku = $order->items()->sole()->sku;
        Sanctum::actingAs($this->partner);

        $this->getJson(route('mobile.v1.cases.create', $order->public_id))
            ->assertOk()
            ->assertJsonPath('can_request', true)
            ->assertJsonPath('eligibility.lines.0.returnable', 3)
            ->assertJsonPath('estimate.return_charge.amount', '40.00');

        $this->postJson(route('mobile.v1.cases.estimate', $order->public_id), ['type' => 'exchange', 'lines' => [$sku => 2], 'estimated_total' => '0.01'])
            ->assertOk()
            ->assertJsonPath('estimated_total.amount', '120.00');
        $this->postJson(route('mobile.v1.cases.estimate', $order->public_id), ['type' => 'exchange', 'lines' => [$sku => 4]])
            ->assertUnprocessable();

        $body = [
            'type' => 'return', 'reason' => 'not_as_described', 'description' => 'The size is wrong.',
            'lines' => [$sku => 2], 'confirmed' => true, 'idempotency_key' => 'one-request', 'return_charge' => '0.01',
        ];

        $created = $this->postJson(route('mobile.v1.cases.store', $order->public_id), $body)->assertCreated();
        $this->postJson(route('mobile.v1.cases.store', $order->public_id), $body)->assertCreated();

        $case = OrderReturn::query()->sole();

        expect($created->json('case.id'))->toBe($case->public_id)
            ->and($case->case_type)->toBe(ReturnCaseType::Return)
            ->and($case->responsible_party)->toBe(ReturnParty::Partner)
            ->and($case->return_charge->toDecimal())->toBe('40.00');

        $this->getJson(route('mobile.v1.cases.index', ['type' => 'return']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('filters.type', 'return');
        $this->getJson(route('mobile.v1.cases.index', ['type' => 'exchange']))->assertJsonCount(0, 'data');
        $this->getJson(route('mobile.v1.cases.show', $case->public_id))->assertOk()->assertJsonPath('case.can_cancel', true);

        $this->postJson(route('mobile.v1.cases.cancel', $case->public_id))->assertOk();

        expect($case->refresh()->status)->toBe(ReturnStatus::Cancelled);
    });

    it('refuses more than can come back with 422 and makes no case', function () {
        $order = caseTestDeliveredOrder();
        $sku = $order->items()->sole()->sku;
        Sanctum::actingAs($this->partner);

        $this->postJson(route('mobile.v1.cases.store', $order->public_id), [
            'type' => 'return', 'reason' => 'not_as_described', 'lines' => [$sku => 4], 'confirmed' => true, 'idempotency_key' => 'too-many',
        ])->assertUnprocessable()->assertJsonValidationErrors('lines');

        expect(OrderReturn::query()->count())->toBe(0);
    });

    it('is a 404 for another Partner\'s order and case', function () {
        $order = caseTestDeliveredOrder();
        $case = caseTestOpenReturn($order);

        Sanctum::actingAs($this->stranger->owner);

        $this->getJson(route('mobile.v1.cases.create', $order->public_id))->assertNotFound();
        $this->postJson(route('mobile.v1.cases.estimate', $order->public_id), ['type' => 'return', 'lines' => []])->assertNotFound();
        $this->postJson(route('mobile.v1.cases.store', $order->public_id), [])->assertNotFound();
        $this->getJson(route('mobile.v1.cases.show', $case->public_id))->assertNotFound();
        $this->postJson(route('mobile.v1.cases.cancel', $case->public_id))->assertNotFound();
        $this->getJson(route('mobile.v1.cases.index'))->assertOk()->assertJsonCount(0, 'data');

        expect($case->refresh()->status)->not->toBe(ReturnStatus::Cancelled);
    });
});

describe('evidence', function () {
    it('opens with the bearer token for its owner only, and only an unsent draft can be taken back', function () {
        $order = caseTestDeliveredOrder();
        Sanctum::actingAs($this->partner);

        $draft = $this->post(route('mobile.v1.cases.evidence.store'), ['order' => $order->public_id, 'file' => caseTestPng('photo.png')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('preview_url', fn (string $url) => str_contains($url, '/api/mobile/v1/cases/evidence/'))
            ->json('id');

        $this->get(route('mobile.v1.cases.evidence.show', $draft))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        Sanctum::actingAs($this->stranger->owner);

        $this->get(route('mobile.v1.cases.evidence.show', $draft))->assertNotFound();
        $this->deleteJson(route('mobile.v1.cases.evidence.destroy', $draft))->assertNotFound();
        $this->post(route('mobile.v1.cases.evidence.store'), ['order' => $order->public_id, 'file' => caseTestPng()], ['Accept' => 'application/json'])->assertNotFound();

        Sanctum::actingAs($this->partner);

        $this->deleteJson(route('mobile.v1.cases.evidence.destroy', $draft))->assertOk()->assertJsonPath('removed', true);
    });
});

it('needs a token and an activated business', function () {
    $this->getJson(route('mobile.v1.damages.index'))->assertUnauthorized();
    $this->getJson(route('mobile.v1.cases.index'))->assertUnauthorized();

    Sanctum::actingAs(testBusinessAccount(AccountStatus::PaymentVerificationPending)->owner);

    $this->getJson(route('mobile.v1.damages.index'))->assertForbidden()->assertJsonPath('code', 'account_not_activated');
    $this->getJson(route('mobile.v1.cases.index'))->assertForbidden()->assertJsonPath('code', 'account_not_activated');
});
