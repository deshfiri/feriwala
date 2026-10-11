<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\ExternalStore\Enums\ExternalStoreStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\Order;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * One list of every order a business has (D29) — its websites, its connected
 * stores, its wholesale purchases — each still what it is, and nobody else's.
 */

beforeEach(function () {
    $this->account = externalStoreAccount();
    $this->owner = $this->account->owner;
    $this->store = externalStoreConnected($this->account);
});

function unifiedOrdersWebsiteOrder(BusinessAccount $account, string $customer = 'Ayesha Rahman'): Order
{
    $website = Website::factory()->forAccount($account)->active()->create(['name' => 'Ayesha Fashion']);
    $buyer = WebsiteCustomer::create([
        'website_id' => $website->id,
        'mobile' => '+88017'.random_int(10000000, 99999999),
        'name' => $customer,
    ]);

    return Order::factory()->create([
        'source' => OrderSource::Website,
        'business_account_id' => $account->id,
        'website_id' => $website->id,
        'website_customer_id' => $buyer->id,
        'storefront_order_reference' => 'SF-'.Str::upper(Str::random(8)),
        'placed_by' => null,
        'cart_id' => null,
        'customer' => ['name' => $customer, 'mobile' => $buyer->mobile],
    ]);
}

describe('the list', function () {
    it('shows website, external and wholesale orders together, each marked with its source', function () {
        $website = unifiedOrdersWebsiteOrder($this->account);
        $wholesale = Order::factory()->create(['business_account_id' => $this->account->id]);
        $external = externalStoreRecordOrder($this->store);

        $this->actingAs($this->owner)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('orders/index')
                ->has('orders.data', 3)
                ->where('orders.total', 3)
                ->where('orders.data', fn ($rows) => collect($rows)->pluck('kind')->sort()->values()->all() === ['external', 'website', 'wholesale'])
                ->where('orders.data', fn ($rows) => collect($rows)->firstWhere('id', $external->public_id)['href'] === route('orders.external.show', $external->public_id))
                ->where('orders.data', fn ($rows) => collect($rows)->firstWhere('id', $website->public_id)['store'] === 'Ayesha Fashion')
                ->where('orders.data', fn ($rows) => collect($rows)->firstWhere('id', $wholesale->public_id)['kind'] === 'wholesale'));
    });

    it('shows no other business\'s orders, of any kind', function () {
        $other = externalStoreAccount();
        unifiedOrdersWebsiteOrder($other);
        Order::factory()->create(['business_account_id' => $other->id]);
        externalStoreRecordOrder(externalStoreConnected($other, 'other.example.com'));

        $mine = externalStoreRecordOrder($this->store);

        $this->actingAs($this->owner)
            ->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $mine->public_id));
    });

    it('filters by source, store and status, and searches by number and customer, on the server', function () {
        unifiedOrdersWebsiteOrder($this->account, 'Ayesha Rahman');
        externalStoreRecordOrder($this->store, ['status' => 'delivered']);
        externalStoreRecordOrder($this->store, ['id' => 2, 'order_number' => 'SECOND-77', 'status' => 'cancelled', 'customer' => ['name' => 'Rafi Ahmed']]);

        $count = fn (array $query) => $this->actingAs($this->owner)->get(route('orders.index', $query))->viewData('page')['props']['orders']['total'];

        expect($count(['source' => 'external']))->toBe(2)
            ->and($count(['source' => 'website']))->toBe(1)
            ->and($count(['store' => 'external:'.$this->store->public_id]))->toBe(2)
            ->and($count(['status' => 'cancelled']))->toBe(1)
            ->and($count(['search' => 'second-77']))->toBe(1)
            ->and($count(['search' => 'rafi']))->toBe(1)
            ->and($count(['search' => 'Ayesha']))->toBe(1);
    });

    it('ignores a sort column it does not know', function () {
        externalStoreRecordOrder($this->store);

        $this->actingAs($this->owner)
            ->get(route('orders.index', ['sort' => 'id; drop table orders', 'direction' => 'sideways']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.sort', 'placed_at')->where('filters.direction', 'desc'));
    });

    it('is closed to a member without order access', function () {
        $staff = User::factory()->staffOf($this->account, AccountRole::Staff)->create();

        $this->actingAs($staff)->get(route('orders.index'))->assertForbidden();
    });

    it('is open to a manager', function () {
        $manager = User::factory()->staffOf($this->account, AccountRole::Manager)->create();

        $this->actingAs($manager)->get(route('orders.index'))->assertOk();
    });
});

describe('the export', function () {
    it('exports only this business\'s orders, with formulas neutralised', function () {
        externalStoreRecordOrder($this->store, ['customer' => ['name' => '=HYPERLINK("http://evil")']]);
        externalStoreRecordOrder(externalStoreConnected(externalStoreAccount(), 'other.example.com'), ['order_number' => 'THEIRS-1']);

        $response = $this->actingAs($this->owner)->get(route('orders.export'));

        $csv = $response->assertOk()->streamedContent();

        expect($csv)->toContain('DF-2026-001234')
            ->not->toContain('THEIRS-1')
            ->toContain("'=HYPERLINK");
    });
});

describe('an external order', function () {
    it('shows the order as the store reported it, with its history', function () {
        $order = externalStoreRecordOrder($this->store);

        $this->actingAs($this->owner)
            ->get(route('orders.external.show', $order->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('orders/external')
                ->where('order.number', 'DF-2026-001234')
                ->where('order.store.name', 'Khalid Fashion')
                ->where('order.totals.total.amount', '960.00')
                ->where('order.lines.0.sku', 'DF-A1B2C3')
                ->where('order.customer.phone', '01700000000')
                ->has('timeline', 1)
                ->where('timeline.0.type', 'imported')
                ->where('timeline.0.source', 'external_store')
                ->where('timeline.0.new', 'confirmed'));
    });

    it('offers no way to change it: the store stays the source of truth', function (string $method) {
        $order = externalStoreRecordOrder($this->store);

        $this->actingAs($this->owner)
            ->call($method, route('orders.external.show', $order->public_id), ['source_status' => 'cancelled'])
            ->assertMethodNotAllowed();

        expect($order->refresh()->source_status)->toBe('confirmed');
    })->with(['POST', 'PUT', 'PATCH', 'DELETE']);

    it('is not found for another business', function () {
        $theirs = externalStoreRecordOrder(externalStoreConnected(externalStoreAccount(), 'other.example.com'));

        $this->actingAs($this->owner)->get(route('orders.external.show', $theirs->public_id))->assertNotFound();
    });

    it('stays readable after its store is disconnected', function () {
        $order = externalStoreRecordOrder($this->store);
        $this->store->forceFill(['api_key' => null, 'api_secret' => null, 'webhook_secret' => null, 'disconnected_at' => now()]);
        $this->store->status = ExternalStoreStatus::Disconnected;
        $this->store->save();

        $this->actingAs($this->owner)->get(route('orders.external.show', $order->public_id))->assertOk();
    });
});

describe('a website order\'s history', function () {
    it('names the partner\'s own people and never a Feriwala staff member or an internal note', function () {
        $order = unifiedOrdersWebsiteOrder($this->account);
        $staffMember = User::factory()->create(['name' => 'Platform Operator']);

        $order->recordStatusChange(null, $order->status, new StatusChange(
            actorId: $staffMember->id,
            internalNote: 'Customer looked suspicious',
            publicNote: 'Order received.',
        ), ['source' => OrderStatusChangeSource::Staff->value, 'notification_status' => 'not_required']);

        $response = $this->actingAs($this->owner)
            ->get(route('websites.orders.show', [$order->website->public_id, $order->public_id]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.0.source', 'staff')
                ->where('history.0.actor', null)
                ->where('history.0.description', 'Order received.'));

        expect($response->getContent())
            ->not->toContain('Customer looked suspicious')
            ->not->toContain('Platform Operator');
    });
});
