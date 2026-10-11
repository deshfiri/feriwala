<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\ExternalStore\Actions\DispatchExternalStoreSyncs;
use App\Domain\ExternalStore\Actions\ImportExternalOrders;
use App\Domain\ExternalStore\Enums\ExternalStoreStatus;
use App\Domain\ExternalStore\Jobs\SyncExternalStore;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Domain\Package\Actions\AssignPackage;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * What a package allows in external stores, over time (D29): an expired term
 * and a downgrade stop new connections and syncing, the limit holds however a
 * store is (re)connected, and the scheduler queues only what may sync.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    externalStoreFakeDns();
});

/**
 * @param  array<string, string|null>  $features
 */
function externalStoreEntitlementPackage(array $features): Package
{
    $package = Package::create([
        'slug' => 'stores-'.Str::lower(Str::random(8)),
        'name' => 'Stores package',
        'fee' => Money::fromDecimal('1000.00'),
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

    foreach ($features as $feature => $value) {
        $package->features()->create(['feature' => $feature, 'value' => $value]);
    }

    return $package->refresh();
}

/**
 * Move the account's subscription past its end, grace included.
 */
function externalStoreEntitlementExpire(BusinessAccount $account): void
{
    UserPackage::query()
        ->where('business_account_id', $account->id)
        ->update(['expires_at' => now()->subDays(30), 'grace_ends_at' => now()->subDays(16)]);
}

/**
 * @return array<string, string>
 */
function externalStoreEntitlementForm(string $host = 'new.example.com'): array
{
    return [
        'platform' => 'dfcommerce',
        'name' => 'Another shop',
        'base_url' => 'https://'.$host,
        'api_key' => 'key',
        'api_secret' => 'secret',
    ];
}

describe('an expired package', function () {
    it('refuses a new connection', function () {
        Http::fake();
        $account = externalStoreAccount(limit: 3);
        externalStoreEntitlementExpire($account);

        $this->actingAs($account->owner)
            ->post(route('stores.store'), externalStoreEntitlementForm())
            ->assertSessionHasErrors(['store' => __('stores.refused.not_entitled')]);

        Http::assertNothingSent();
    });

    it('stops syncing, and keeps the store and its orders', function () {
        Http::fake();
        $account = externalStoreAccount();
        $store = externalStoreConnected($account);
        externalStoreRecordOrder($store);
        externalStoreEntitlementExpire($account);

        expect(app(ImportExternalOrders::class)->handle($store))->toBeNull()
            ->and($store->refresh()->status)->toBe(ExternalStoreStatus::Connected)
            ->and($store->orders()->count())->toBe(1);

        Http::assertNothingSent();
    });

    it('still lets the owner reach the stores they already have', function () {
        $account = externalStoreAccount();
        externalStoreConnected($account);
        externalStoreEntitlementExpire($account);

        $this->actingAs($account->owner)
            ->get(route('stores.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('account.managesStores', true)
                ->where('allowance.allowed', false)
                ->has('stores', 1));
    });

    it('hides the stores door from an account that never had one', function () {
        $account = externalStoreAccount();
        externalStoreEntitlementExpire($account);

        $this->actingAs($account->owner)
            ->get(route('stores.index'))
            ->assertInertia(fn (Assert $page) => $page->where('account.managesStores', false));
    });
});

describe('a downgraded package', function () {
    beforeEach(function () {
        Notification::fake();
        $this->admin = User::factory()->create();
    });

    it('refuses a connection beyond the lower limit, and keeps the stores already connected syncing', function () {
        Http::fake(fn () => externalStoreOrdersPage([]));
        $account = externalStoreAccount(limit: 3);
        $first = externalStoreConnected($account, 'one.example.com');
        externalStoreConnected($account, 'two.example.com');

        app(AssignPackage::class)->handle($account, externalStoreEntitlementPackage([
            PackageFeature::ExternalStoreAccess->value => '1',
            PackageFeature::ExternalStoreLimit->value => '1',
        ]), $this->admin, 'Downgraded', CarbonImmutable::now());

        $this->actingAs($account->owner)
            ->post(route('stores.store'), externalStoreEntitlementForm())
            ->assertSessionHasErrors(['store' => __('stores.refused.limit_reached', ['limit' => 1])]);

        Http::assertNothingSent();

        // Existing connections are not cut off by the downgrade.
        expect(app(ImportExternalOrders::class)->handle($first))->not->toBeNull();
    });

    it('stops syncing when the new package does not include stores at all', function () {
        Http::fake();
        $account = externalStoreAccount();
        $store = externalStoreConnected($account);

        app(AssignPackage::class)->handle($account, externalStoreEntitlementPackage([
            PackageFeature::ExternalStoreAccess->value => '0',
        ]), $this->admin, 'Downgraded', CarbonImmutable::now());

        expect(app(ImportExternalOrders::class)->handle($store))->toBeNull();
        Http::assertNothingSent();
    });
});

describe('the store limit', function () {
    it('holds when a disconnected store is reconnected', function () {
        Http::fake();
        $account = externalStoreAccount(limit: 1);
        externalStoreConnected($account, 'one.example.com');
        $old = externalStoreConnected($account, 'two.example.com', ExternalStoreStatus::Disconnected);

        $this->actingAs($account->owner)
            ->put(route('stores.update', $old->public_id), ['name' => 'Two', 'api_key' => 'k', 'api_secret' => 's'])
            ->assertSessionHasErrors(['store' => __('stores.refused.limit_reached', ['limit' => 1])]);

        expect($old->refresh()->status)->toBe(ExternalStoreStatus::Disconnected);
        Http::assertNothingSent();
    });

    it('treats a package with no limit row as allowing none', function () {
        Http::fake();
        $account = testAccountWithPackageFeatures([PackageFeature::ExternalStoreAccess->value => '1'], AccountStatus::Active);

        $this->actingAs($account->owner)
            ->post(route('stores.store'), externalStoreEntitlementForm())
            ->assertSessionHasErrors(['store' => __('stores.refused.limit_reached', ['limit' => 0])]);
    });
});

describe('the scheduled sync', function () {
    it('queues only live stores of accounts that may sync', function () {
        Queue::fake([SyncExternalStore::class]);

        $eligible = externalStoreConnected(externalStoreAccount(), 'eligible.example.com');
        $failing = externalStoreConnected(externalStoreAccount(), 'failing.example.com', ExternalStoreStatus::Failing);
        externalStoreConnected(externalStoreAccount(), 'gone.example.com', ExternalStoreStatus::Disconnected);
        externalStoreConnected(externalStoreAccount(entitled: false), 'unentitled.example.com');

        $expired = externalStoreAccount();
        externalStoreConnected($expired, 'expired.example.com');
        externalStoreEntitlementExpire($expired);

        $suspended = externalStoreAccount();
        externalStoreConnected($suspended, 'suspended.example.com');
        $suspended->forceFill(['status' => AccountStatus::Suspended])->save();

        expect(app(DispatchExternalStoreSyncs::class)->handle())->toBe(2);

        Queue::assertPushed(SyncExternalStore::class, 2);
        Queue::assertPushed(SyncExternalStore::class, fn (SyncExternalStore $job) => $job->storeId === $eligible->id);
        Queue::assertPushed(SyncExternalStore::class, fn (SyncExternalStore $job) => $job->storeId === $failing->id);
        expect(ExternalStore::query()->count())->toBe(6);
    });
});
