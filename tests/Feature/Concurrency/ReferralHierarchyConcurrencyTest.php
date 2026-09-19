<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Referral\Actions\AttachReferrer;
use Illuminate\Support\Facades\DB;

/*
 * The referral hierarchy under a race, with real processes (D24, P7-11).
 *
 * Two writes that would close a loop between them each pass a check that
 * reads only committed rows — A is not above B, B is not above A — and without
 * serialisation both would commit and the chain would never end. The database
 * guard takes one advisory lock for every hierarchy write, so the second walks
 * the chain after the first has committed and is refused.
 */

beforeEach(function () {
    config()->set('database.connections.referral_race', config('database.connections.pgsql'));
    config()->set('database.default', 'referral_race');

    $this->first = testBusinessAccount(AccountStatus::Active);
    $this->second = testBusinessAccount(AccountStatus::Active);
});

afterEach(function () {
    $accounts = [$this->first->id, $this->second->id];

    DB::table('account_referrals')->whereIn('referred_account_id', $accounts)->delete();
    DB::table('business_accounts')->whereIn('id', $accounts)->delete();
    DB::table('users')->whereIn('id', [$this->first->owner_id, $this->second->owner_id])->delete();
});

/**
 * Run one piece of work in `$count` real processes, started together.
 */
function referralRace(int $count, Closure $work): void
{
    DB::purge('referral_race');

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
                // The loser is refused; what happened is read from the database.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

it('lets only one of two writes that would close a loop commit', function () {
    $ids = [$this->first->id, $this->second->id];

    referralRace(2, function (int $worker) use ($ids) {
        $account = BusinessAccount::query()->findOrFail($ids[$worker]);
        $referrer = BusinessAccount::query()->findOrFail($ids[1 - $worker]);

        app(AttachReferrer::class)->atRegistration($account, $referrer, null);
    });

    expect(DB::table('account_referrals')->whereIn('referred_account_id', $ids)->count())->toBe(1);
});
