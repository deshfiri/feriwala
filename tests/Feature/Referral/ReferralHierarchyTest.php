<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Referral\Actions\AttachReferrer;
use App\Domain\Referral\Enums\ReferralAttachment;
use App\Domain\Referral\Exceptions\ReferralRefused;
use App\Domain\Referral\Models\AccountReferral;
use App\Domain\Referral\Queries\ReferralHierarchy;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The referral hierarchy between business accounts (§25.1, §25.5, D24, P7-11).
 *
 * One direct referrer per account, never itself and never one of its own
 * descendants — refused by the application with a message, and by the
 * database whoever writes the row.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('makes the business behind the typed code the new business\'s direct referrer', function () {
    $referrer = testBusinessAccount(AccountStatus::Active);
    $referrer->owner->forceFill(['referral_code' => 'ABCD2345'])->save();

    $this->post(route('register.store'), [
        'name' => 'Karim Rahman',
        'email' => 'karim@example.com',
        'mobile' => '+8801712345601',
        'password' => testStrongPassword(),
        'password_confirmation' => testStrongPassword(),
        'terms_accepted' => '1',
        'privacy_accepted' => '1',
        'referral_code' => 'abcd-2345',
    ])->assertSessionHasNoErrors();

    $account = User::query()->where('email', 'karim@example.com')->firstOrFail()->ownedAccount;
    $link = AccountReferral::query()->where('referred_account_id', $account?->id)->firstOrFail();

    expect($link->referrer_account_id)->toBe($referrer->id)
        ->and($link->attached_via)->toBe(ReferralAttachment::Registration)
        ->and($link->referral_code)->toBe('ABCD2345')
        ->and($link->locked_at)->toBeNull();
});

it('walks the chain upward level by level and stops where it ends', function () {
    [$top, $second, $third, $fourth] = referralTestChain(4);

    $hierarchy = app(ReferralHierarchy::class);

    expect($hierarchy->ancestors($fourth->id, 10))->toBe([1 => $third->id, 2 => $second->id, 3 => $top->id])
        ->and($hierarchy->ancestors($fourth->id, 2))->toBe([1 => $third->id, 2 => $second->id])
        ->and($hierarchy->ancestors($top->id, 5))->toBe([]);
});

it('refuses self-referral in the application and in the database', function () {
    $account = testBusinessAccount(AccountStatus::Active);

    expect(fn () => app(AttachReferrer::class)->atRegistration($account, $account, null))
        ->toThrow(ReferralRefused::class, __('referral.refused.self_referral'));

    expect(fn () => DB::transaction(fn () => DB::table('account_referrals')->insert([
        'referred_account_id' => $account->id,
        'referrer_account_id' => $account->id,
        'attached_via' => 'registration',
    ])))->toThrow(QueryException::class);
});

it('refuses a referrer that descends from the account, in the application and in the database', function () {
    [$top, , $bottom] = referralTestChain(3);

    expect(fn () => app(AttachReferrer::class)->byStaff($top, $bottom, testPlatformStaff(PlatformRole::ReferralManager), 'Correcting a mistyped code.'))
        ->toThrow(ReferralRefused::class, __('referral.refused.circular'));

    expect(fn () => DB::transaction(fn () => DB::table('account_referrals')->insert([
        'referred_account_id' => $top->id,
        'referrer_account_id' => $bottom->id,
        'attached_via' => 'backfill',
    ])))->toThrow(QueryException::class, 'its own ancestor');

    expect(AccountReferral::query()->where('referred_account_id', $top->id)->exists())->toBeFalse();
});

it('gives an account at most one direct referrer', function () {
    [$top, $referred] = referralTestChain(2);
    $other = testBusinessAccount(AccountStatus::Active);

    expect(fn () => DB::transaction(fn () => DB::table('account_referrals')->insert([
        'referred_account_id' => $referred->id,
        'referrer_account_id' => $other->id,
        'attached_via' => 'backfill',
    ])))->toThrow(QueryException::class);

    expect(AccountReferral::query()->where('referred_account_id', $referred->id)->value('referrer_account_id'))->toBe($top->id);
});

it('lets a person correct a referrer before it is locked, with a reason, audited', function () {
    [$wrong, $account] = referralTestChain(2);
    $right = testBusinessAccount(AccountStatus::Active);
    $manager = testPlatformStaff(PlatformRole::ReferralManager);

    $link = app(AttachReferrer::class)->byStaff($account, $right, $manager, 'Mistyped code at registration.');

    expect($link->referrer_account_id)->toBe($right->id)
        ->and($link->attached_via)->toBe(ReferralAttachment::Staff);

    $audit = AuditLog::query()->where('action', 'referral.referrer_attached')->firstOrFail();

    expect($audit->actor_id)->toBe($manager->id)
        ->and($audit->before)->toBe(['referrer_account' => $wrong->public_id])
        ->and($audit->after)->toBe(['referrer_account' => $right->public_id])
        ->and($audit->reason)->toBe('Mistyped code at registration.');
});

it('never changes or removes a referral once a qualifying event has locked it', function () {
    [$referrer, $account] = referralTestChain(2);
    $other = testBusinessAccount(AccountStatus::Active);

    AccountReferral::query()->where('referred_account_id', $account->id)->update(['locked_at' => now()]);

    expect(fn () => app(AttachReferrer::class)->byStaff($account, $other, testPlatformStaff(PlatformRole::ReferralManager), 'Trying to move it.'))
        ->toThrow(ReferralRefused::class, __('referral.refused.locked'));

    expect(fn () => DB::transaction(fn () => DB::table('account_referrals')->where('referred_account_id', $account->id)->update(['referrer_account_id' => $other->id])))
        ->toThrow(QueryException::class, 'cannot change');

    expect(AccountReferral::query()->where('referred_account_id', $account->id)->value('referrer_account_id'))->toBe($referrer->id);
});

it('refuses a referral from a business that cannot trade', function () {
    $account = testBusinessAccount(AccountStatus::Active);
    $suspended = testBusinessAccount(AccountStatus::Suspended);

    expect(fn () => app(AttachReferrer::class)->byStaff($account, $suspended, testPlatformStaff(PlatformRole::ReferralManager), 'Attaching a suspended referrer.'))
        ->toThrow(ReferralRefused::class, __('referral.refused.referrer_not_active'));
});

it('carries registrations made before the hierarchy existed across, account to account', function () {
    $referrer = testBusinessAccount(AccountStatus::Active);
    $referred = testBusinessAccount(AccountStatus::Registered);
    $referred->owner->forceFill(['referred_by_user_id' => $referrer->owner_id])->save();

    $migration = require database_path('migrations/2026_09_29_100000_create_account_referrals.php');
    $migration->down();
    $migration->up();

    $link = AccountReferral::query()->where('referred_account_id', $referred->id)->firstOrFail();

    expect($link->referrer_account_id)->toBe($referrer->id)
        ->and($link->attached_via)->toBe(ReferralAttachment::Backfill);
});
