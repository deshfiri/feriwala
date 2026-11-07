<?php

use App\Domain\Account\Models\BusinessAccount;
use App\Support\References\PartnerNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Every partner has an identity number: `BJ` and eleven digits, drawn when the
 * account is created, and filled in for older accounts by
 * `partners:assign-numbers`.
 */
it('draws BJ and eleven digits, never starting with a zero', function () {
    foreach (range(1, 300) as $draw) {
        expect(PartnerNumber::generate())->toMatch('/^BJ[1-9][0-9]{10}$/');
    }

    expect(PartnerNumber::matches('BJ56987412548'))->toBeTrue()
        ->and(PartnerNumber::matches('BJ5698741254'))->toBeFalse()
        ->and(PartnerNumber::matches('BJ05698741254'))->toBeFalse()
        ->and(PartnerNumber::matches('XX56987412548'))->toBeFalse();
});

it('gives every new partner account its own number', function () {
    $numbers = BusinessAccount::factory()->count(5)->create()
        ->map(fn (BusinessAccount $account): ?string => $account->fresh()->partner_number);

    expect($numbers->every(fn (?string $number): bool => $number !== null && PartnerNumber::matches($number)))->toBeTrue()
        ->and($numbers->unique())->toHaveCount(5);
});

it('keeps a number it is given', function () {
    $account = BusinessAccount::factory()->create(['partner_number' => 'BJ12345678901']);

    expect($account->fresh()->partner_number)->toBe('BJ12345678901');
});

it('never hands out a number that two accounts could share', function () {
    BusinessAccount::factory()->create(['partner_number' => 'BJ12345678901']);

    expect(fn () => BusinessAccount::factory()->create(['partner_number' => 'BJ12345678901']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('gives partners who joined earlier a number, closed ones included, and leaves existing numbers alone', function () {
    $kept = BusinessAccount::factory()->create(['partner_number' => 'BJ10000000001']);
    $earlier = BusinessAccount::factory()->count(2)->create();
    $closed = BusinessAccount::factory()->create();
    $closed->delete();

    // As they were before partner numbers existed.
    DB::table('business_accounts')
        ->whereIn('id', [...$earlier->modelKeys(), $closed->getKey()])
        ->update(['partner_number' => null]);

    $updatedAt = DB::table('business_accounts')->where('id', $earlier->first()->getKey())->value('updated_at');

    $this->artisan('partners:assign-numbers')->assertSuccessful();

    $assigned = DB::table('business_accounts')
        ->whereIn('id', [...$earlier->modelKeys(), $closed->getKey()])
        ->pluck('partner_number');

    expect($assigned)->toHaveCount(3)
        ->and($assigned->every(fn (?string $number): bool => $number !== null && PartnerNumber::matches($number)))->toBeTrue()
        ->and($assigned->unique())->toHaveCount(3)
        ->and($kept->fresh()->partner_number)->toBe('BJ10000000001')
        ->and(DB::table('business_accounts')->where('id', $earlier->first()->getKey())->value('updated_at'))->toBe($updatedAt);
});

it('changes nothing when run again', function () {
    $account = BusinessAccount::factory()->create();
    DB::table('business_accounts')->where('id', $account->getKey())->update(['partner_number' => null]);

    $this->artisan('partners:assign-numbers')->assertSuccessful();
    $first = $account->fresh()->partner_number;

    $this->artisan('partners:assign-numbers')
        ->expectsOutputToContain('Every partner already has a number.')
        ->assertSuccessful();

    expect($account->fresh()->partner_number)->toBe($first);
});

it('counts on a dry run and changes nothing', function () {
    $account = BusinessAccount::factory()->create();
    DB::table('business_accounts')->where('id', $account->getKey())->update(['partner_number' => null]);

    $this->artisan('partners:assign-numbers', ['--dry-run' => true])
        ->expectsOutputToContain('1 partner(s) would get a number')
        ->assertSuccessful();

    expect($account->fresh()->partner_number)->toBeNull();
});
