<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * The mobile orders surface (D29, D30, D34): every order in one list, a partner's
 * Store Orders, and the stores connected before. Read through the same queries as
 * the web screens, and only ever through the signed-in person's own account.
 */

beforeEach(function () {
    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->owner = $this->account->owner;
    $this->other = testBusinessAccount(AccountStatus::Active);
});

function mobileOrdersStoreOrder(BusinessAccount $account, array $attributes = []): Order
{
    $website = Website::factory()->forAccount($account)->active()->create();
    $buyer = WebsiteCustomer::create([
        'website_id' => $website->id,
        'mobile' => '+88017'.random_int(10000000, 99999999),
        'name' => 'Ayesha Rahman',
    ]);

    return Order::factory()->create([
        'business_account_id' => $account->id,
        'source' => OrderSource::Website,
        'website_id' => $website->id,
        'website_customer_id' => $buyer->id,
        'storefront_order_reference' => 'SF-'.Str::upper(Str::random(8)),
        'placed_by' => null,
        'cart_id' => null,
        'customer' => ['name' => $buyer->name, 'mobile' => $buyer->mobile],
        ...$attributes,
    ]);
}

describe('the unified orders list', function () {
    test('lists the account\'s own orders from every source and none of anybody else\'s', function () {
        $mine = externalStoreRecordOrder(externalStoreConnected($this->account));
        $theirs = externalStoreRecordOrder(externalStoreConnected($this->other, 'other.example.com'));
        $store = mobileOrdersStoreOrder($this->account);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson(route('mobile.v1.orders.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure(['links', 'filters', 'options' => ['stores', 'statuses', 'payments', 'sources']]);

        $ids = collect($response->json('data'))->pluck('id');

        expect($ids)->toContain($mine->public_id)->toContain($store->public_id)->not->toContain($theirs->public_id);
        expect($response->json('data.0'))->not->toHaveKey('href');
    });

    test('filters by source and ignores values it does not recognise', function () {
        externalStoreRecordOrder(externalStoreConnected($this->account));
        mobileOrdersStoreOrder($this->account);
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.orders.index', ['source' => 'external']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'external');

        $this->getJson(route('mobile.v1.orders.index', ['source' => 'nonsense', 'sort' => 'nonsense']))
            ->assertOk()
            ->assertJsonPath('filters.source', null)
            ->assertJsonPath('filters.sort', 'placed_at')
            ->assertJsonCount(2, 'data');
    });

    test('the export carries this account\'s rows only', function () {
        $mine = externalStoreRecordOrder(externalStoreConnected($this->account));
        $theirs = externalStoreRecordOrder(externalStoreConnected($this->other, 'other.example.com'));
        Sanctum::actingAs($this->owner);

        $response = $this->get(route('mobile.v1.orders.export'))->assertOk();

        $csv = $response->streamedContent();

        expect($csv)->toContain((string) $mine->order_number)->not->toContain('"'.$theirs->order_number.'"');
        expect($response->headers->get('Content-Type'))->toContain('text/csv');
    });

    test('someone without the right to view orders is refused', function () {
        $member = User::factory()->create();
        $this->account->memberships()->create(['user_id' => $member->id, 'role' => AccountRole::Staff]);

        Sanctum::actingAs($member);

        $this->getJson(route('mobile.v1.orders.index'))->assertForbidden();
        $this->get(route('mobile.v1.orders.export'))->assertForbidden();
    });

    test('is closed until the business is activated, and needs a token', function () {
        $pending = testBusinessAccount(AccountStatus::PaymentVerificationPending);

        $this->getJson(route('mobile.v1.orders.index'))->assertUnauthorized();

        Sanctum::actingAs($pending->owner);

        $this->getJson(route('mobile.v1.orders.index'))
            ->assertForbidden()
            ->assertJsonPath('code', 'account_not_activated');
    });
});

describe('an external order', function () {
    test('is shown as the store recorded it, with its money as flat Taka', function () {
        $order = externalStoreRecordOrder(externalStoreConnected($this->account));
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.orders.external.show', $order->public_id))
            ->assertOk()
            ->assertJsonPath('data.id', $order->public_id)
            ->assertJsonPath('data.totals.total.currency', 'BDT')
            ->assertJsonPath('data.store.id', $order->store->public_id)
            ->assertJsonStructure(['data' => ['totals' => ['total' => ['amount', 'currency', 'formatted']], 'lines'], 'timeline']);
    });

    test('is not found by borrowing another account\'s public id', function () {
        $theirs = externalStoreRecordOrder(externalStoreConnected($this->other, 'other.example.com'));
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.orders.external.show', $theirs->public_id))->assertNotFound();
    });
});

describe('store orders', function () {
    test('lists only the orders this account\'s storefronts took', function () {
        $mine = mobileOrdersStoreOrder($this->account);
        $theirs = mobileOrdersStoreOrder($this->other);
        Order::factory()->create(['business_account_id' => $this->account->id]);

        Sanctum::actingAs($this->owner);

        $response = $this->getJson(route('mobile.v1.orders.store-orders.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->public_id)
            ->assertJsonPath('data.0.total.currency', 'BDT')
            ->assertJsonStructure(['links', 'meta', 'filters', 'steps', 'storefronts']);

        expect($response->getContent())->not->toContain($theirs->public_id);
    });

    test('an order is shown in full with what this person may do', function () {
        $order = mobileOrdersStoreOrder($this->account);
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.orders.store-orders.show', $order->public_id))
            ->assertOk()
            ->assertJsonPath('data.id', $order->public_id)
            ->assertJsonPath('can.advance', false)
            ->assertJsonPath('can.cancel', true)
            ->assertJsonPath('timeline.next_action', null)
            ->assertJsonStructure(['data' => ['items'], 'timeline', 'courier', 'cases', 'exchange_origin', 'can']);
    });

    test('a pending order is cancelled with a reason', function () {
        $order = mobileOrdersStoreOrder($this->account);
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.orders.store-orders.cancel', $order->public_id), ['reason' => 'Customer changed their mind'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('can.cancel', false);

        expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($order->cancellation_reason)->toBe('Customer changed their mind');
    });

    test('cancelling needs a reason and is refused once the order is being processed', function () {
        $pending = mobileOrdersStoreOrder($this->account);
        $processing = mobileOrdersStoreOrder($this->account, ['status' => OrderStatus::Processing]);
        Sanctum::actingAs($this->owner);

        $this->postJson(route('mobile.v1.orders.store-orders.cancel', $pending->public_id), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->postJson(route('mobile.v1.orders.store-orders.cancel', $processing->public_id), ['reason' => 'Too late'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        expect($processing->refresh()->status)->toBe(OrderStatus::Processing);
    });

    test('another account\'s order can be neither read nor cancelled', function () {
        $theirs = mobileOrdersStoreOrder($this->other);
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.orders.store-orders.show', $theirs->public_id))->assertNotFound();
        $this->postJson(route('mobile.v1.orders.store-orders.cancel', $theirs->public_id), ['reason' => 'Not mine'])->assertNotFound();

        expect($theirs->refresh()->status)->toBe(OrderStatus::PaymentPending);
    });

    test('are closed until the business is activated, and need a token', function () {
        $pending = testBusinessAccount(AccountStatus::PaymentVerificationPending);

        $this->getJson(route('mobile.v1.orders.store-orders.index'))->assertUnauthorized();

        Sanctum::actingAs($pending->owner);

        $this->getJson(route('mobile.v1.orders.store-orders.index'))
            ->assertForbidden()
            ->assertJsonPath('code', 'account_not_activated');
    });
});

describe('connected stores', function () {
    test('lists this account\'s stores and never a credential', function () {
        $mine = externalStoreConnected($this->account);
        externalStoreConnected($this->other, 'other.example.com');
        Sanctum::actingAs($this->owner);

        $response = $this->getJson(route('mobile.v1.orders.stores.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->public_id)
            ->assertJsonPath('data.0.status', 'connected');

        $this->getJson(route('mobile.v1.orders.stores.show', $mine->public_id))
            ->assertOk()
            ->assertJsonPath('data.api_key_hint', '1234')
            ->assertJsonPath('data.has_credentials', true);

        foreach (['key-1234', 'secret-5678', 'webhook-secret'] as $secret) {
            expect($response->getContent())->not->toContain($secret);
            expect($this->getJson(route('mobile.v1.orders.stores.show', $mine->public_id))->getContent())->not->toContain($secret);
        }
    });

    test('another account\'s store is not found', function () {
        $theirs = externalStoreConnected($this->other, 'other.example.com');
        Sanctum::actingAs($this->owner);

        $this->getJson(route('mobile.v1.orders.stores.show', $theirs->public_id))->assertNotFound();
    });

    test('are closed until the business is activated, and need a token', function () {
        $pending = testBusinessAccount(AccountStatus::PaymentVerificationPending);

        $this->getJson(route('mobile.v1.orders.stores.index'))->assertUnauthorized();

        Sanctum::actingAs($pending->owner);

        $this->getJson(route('mobile.v1.orders.stores.index'))
            ->assertForbidden()
            ->assertJsonPath('code', 'account_not_activated');
    });
});
