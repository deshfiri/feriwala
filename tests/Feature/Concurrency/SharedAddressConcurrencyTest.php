<?php

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Address\Actions\SaveSharedAddress;
use App\Domain\Address\Actions\SetDefaultSharedAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\ClientAddressType;
use App\Domain\Address\Models\SharedAddress;
use Illuminate\Support\Facades\DB;

/*
 * One active default per (owner_type, owner_id, type) is enforced twice
 * (Address batch correction): SetDefaultSharedAddress takes out a row lock
 * and clears every other default inside one transaction, and
 * shared_addresses_one_default_per_owner_type is a partial unique index that
 * holds even if a write reaches the table any other way. Real
 * pcntl_fork() workers, the same shape as SupplierWalletConcurrencyTest,
 * racing on a connection whose writes actually commit — never trusted at
 * design time.
 */
beforeEach(function () {
    config()->set('database.connections.shared_address_race', config('database.connections.pgsql'));
    config()->set('database.default', 'shared_address_race');

    $this->account = BusinessAccount::factory()->create();
    $this->chain = addressTestLocationChain();
});

afterEach(function () {
    DB::table('shared_addresses')
        ->where('owner_type', AddressOwnerType::BusinessAccount->value)
        ->where('owner_id', $this->account->id)
        ->delete();

    DB::table('business_account_members')->where('business_account_id', $this->account->id)->delete();
    DB::table('business_accounts')->where('id', $this->account->id)->delete();
    DB::table('users')->where('id', $this->account->owner_id)->delete();

    // bd_locations is never-deleted (feriwala_bd_locations_are_never_deleted)
    // — the fixture chain is left behind, the same accepted trade-off already
    // made for other immutable fixture data in this project's race tests.
});

/**
 * Run one piece of work in `$count` real processes, started together.
 */
function sharedAddressRace(int $count, Closure $work): void
{
    DB::purge('shared_address_race');

    $startAt = microtime(true) + 0.3;
    $pids = [];

    for ($worker = 0; $worker < $count; $worker++) {
        $pid = pcntl_fork();

        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

            try {
                $work($worker);
            } catch (Throwable) {
                // Losing the race is the expected outcome for one side of
                // every test here. What happened is read back from the
                // database afterwards, never from an exit code.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

it('never lets two existing addresses both be the default when two workers race to set them', function () {
    $addressA = app(SaveSharedAddress::class)->handle(
        ownerType: AddressOwnerType::BusinessAccount,
        ownerId: $this->account->id,
        type: ClientAddressType::Business->value,
        contactName: 'Address A',
        contactMobile: '+8801711111111',
        divisionId: $this->chain['division']->id,
        districtId: $this->chain['district']->id,
        upazilaId: $this->chain['upazila']->id,
        unionId: $this->chain['union']->id,
        detailedAddress: 'A',
        landmark: null,
        postcode: null,
        makeDefault: true,
    );

    $addressB = app(SaveSharedAddress::class)->handle(
        ownerType: AddressOwnerType::BusinessAccount,
        ownerId: $this->account->id,
        type: ClientAddressType::Business->value,
        contactName: 'Address B',
        contactMobile: '+8801711111112',
        divisionId: $this->chain['division']->id,
        districtId: $this->chain['district']->id,
        upazilaId: $this->chain['upazila']->id,
        unionId: $this->chain['union']->id,
        detailedAddress: 'B',
        landmark: null,
        postcode: null,
    );

    sharedAddressRace(2, function (int $worker) use ($addressA, $addressB) {
        $target = $worker === 0 ? $addressA : $addressB;
        app(SetDefaultSharedAddress::class)->handle($target->fresh());
    });

    $defaults = SharedAddress::query()
        ->where('owner_type', AddressOwnerType::BusinessAccount->value)
        ->where('owner_id', $this->account->id)
        ->where('is_default', true)
        ->count();

    // Never two — the partial unique index would have refused the second
    // commit outright. Exactly one is the expected outcome of a clean race;
    // <= 1 is what the invariant actually promises (a deadlock loser rolls
    // its whole transaction back rather than leaving a torn state).
    expect($defaults)->toBeLessThanOrEqual(1);
})->group('slow');

it('never lets two brand-new addresses both become the default when created simultaneously', function () {
    $accountId = $this->account->id;
    $chain = $this->chain;

    sharedAddressRace(2, function (int $worker) use ($accountId, $chain) {
        app(SaveSharedAddress::class)->handle(
            ownerType: AddressOwnerType::BusinessAccount,
            ownerId: $accountId,
            type: ClientAddressType::Business->value,
            contactName: 'Race '.$worker,
            contactMobile: '+880171111111'.$worker,
            divisionId: $chain['division']->id,
            districtId: $chain['district']->id,
            upazilaId: $chain['upazila']->id,
            unionId: $chain['union']->id,
            detailedAddress: 'Race fixture',
            landmark: null,
            postcode: null,
            makeDefault: true,
        );
    });

    $rows = SharedAddress::query()
        ->where('owner_type', AddressOwnerType::BusinessAccount->value)
        ->where('owner_id', $accountId)
        ->get();

    // Both inserts always succeed (no conflict there); it is the default
    // flag — set in a second transaction, against rows neither worker had
    // any prior lock on — that must never end up true on more than one.
    expect($rows)->toHaveCount(2)
        ->and($rows->where('is_default', true)->count())->toBeLessThanOrEqual(1);
})->group('slow');
