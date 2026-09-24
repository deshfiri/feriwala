<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Referral\Actions\AttachReferrer;
use App\Domain\Referral\Actions\CalculateReferralCommissions;
use App\Domain\Referral\Actions\ReleaseReferralCommission;
use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Models\ReferralCommission;
use App\Domain\Referral\Models\ReferralQualifyingEvent;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/*
 * The same qualifying event cannot pay the chain twice (D24, P7-43), with real
 * processes.
 *
 * Six workers are handed the same activation at the same moment: each asks the
 * engine to record it and then tries to release every commission it finds. The
 * guarantees stack — one event per activation by unique index, one commission
 * per event and level by unique index, the release re-reading each row under a
 * lock, and each posting's own idempotency key — and this asserts what they add
 * up to: one event, one commission per level, one ledger credit per
 * beneficiary.
 *
 * Committed data on a connection of its own, as the other race tests do, and
 * cleared afterwards with the append-only guards briefly off — this is the one
 * place allowed to reach past them.
 */

beforeEach(function () {
    config()->set('database.connections.referral_commission_race', config('database.connections.pgsql'));
    config()->set('database.default', 'referral_commission_race');

    // Everything this test commits is above these marks, and removed afterwards.
    $this->auditFrom = (int) DB::table('audit_logs')->max('id');
    $this->usersFrom = (int) DB::table('users')->max('id');

    referralTestSwitchOn();
    $this->plan = referralTestPlan([['percentage', '10'], ['percentage', '5']]);

    [$this->second, $this->direct] = referralTestChain(2);

    $this->newcomer = testBusinessAccount(AccountStatus::Active);
    app(AttachReferrer::class)->atRegistration($this->newcomer, $this->direct, null);

    $this->payment = Payment::create([
        'business_account_id' => $this->newcomer->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => PaymentStatus::Paid,
        'amount' => Money::fromDecimal('6500.00', Currency::BDT),
        'currency_code' => 'BDT',
        'completed_at' => now(),
    ]);
    $this->payment->allocations()->create(['type' => AllocationType::RegistrationFee, 'amount' => Money::fromDecimal('1500.00', Currency::BDT), 'currency_code' => 'BDT']);
    $this->payment->allocations()->create(['type' => AllocationType::PackageFee, 'amount' => Money::fromDecimal('5000.00', Currency::BDT), 'currency_code' => 'BDT']);
});

afterEach(function () {
    $accounts = [$this->second->id, $this->direct->id, $this->newcomer->id];
    $owners = DB::table('business_accounts')->whereIn('id', $accounts)->pluck('owner_id')->all();
    $wallets = DB::table('wallets')->whereIn('business_account_id', $accounts)->pluck('id')->all();

    $guards = [
        'referral_commissions' => 'referral_commissions_kept',
        'referral_qualifying_events' => 'referral_qualifying_events_kept',
        'referral_plan_levels' => 'referral_plan_levels_written_once',
        'referral_plans' => 'referral_plans_kept',
        'account_referrals' => 'account_referrals_hierarchy_guard',
        'ledger_entries' => 'ledger_entries_no_delete',
        'wallet_transaction_events' => 'wallet_transaction_events_no_delete',
        'audit_logs' => 'audit_logs_no_delete',
    ];

    foreach ($guards as $table => $trigger) {
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
    }

    DB::table('referral_commissions')->where('source_account_id', $this->newcomer->id)->delete();
    DB::table('referral_qualifying_events')->where('source_account_id', $this->newcomer->id)->delete();
    DB::table('referral_plan_levels')->where('referral_plan_id', $this->plan->id)->delete();
    DB::table('referral_plans')->where('id', $this->plan->id)->delete();
    DB::table('account_referrals')->whereIn('referred_account_id', $accounts)->delete();
    DB::table('notifications')->whereIn('notifiable_id', $owners)->delete();
    DB::table('wallet_transaction_events')->whereIn('wallet_id', $wallets)->delete();
    DB::table('ledger_entries')->whereIn('wallet_id', $wallets)->delete();
    DB::table('wallet_transactions')->whereIn('wallet_id', $wallets)->delete();
    DB::table('wallets')->whereIn('id', $wallets)->delete();
    DB::table('payments')->where('id', $this->payment->id)->delete();
    DB::table('audit_logs')->where('id', '>', $this->auditFrom)->delete();
    DB::table('settings')->where('key', 'referral.mlm_enabled')->delete();
    DB::table('business_accounts')->whereIn('id', $accounts)->delete();
    DB::table('users')->where('id', '>', $this->usersFrom)->delete();

    foreach ($guards as $table => $trigger) {
        DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
    }
});

/**
 * Run one piece of work in `$count` real processes, started together.
 */
function referralCommissionRace(int $count, Closure $work): void
{
    DB::purge('referral_commission_race');

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
                // Losing a race is expected; the database says what happened.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

it('pays the chain once when six workers process the same activation at once', function () {
    $accountId = $this->newcomer->id;

    referralCommissionRace(6, function () use ($accountId) {
        app(CalculateReferralCommissions::class)->forActivation(BusinessAccount::query()->findOrFail($accountId));

        ReferralCommission::query()
            ->where('source_account_id', $accountId)
            ->pluck('id')
            ->each(fn (int $id) => app(ReleaseReferralCommission::class)->handle($id));
    });

    $credits = fn (BusinessAccount $account) => (int) DB::table('ledger_entries')
        ->join('wallets', 'wallets.id', '=', 'ledger_entries.wallet_id')
        ->where('wallets.business_account_id', $account->id)
        ->where('ledger_entries.type', LedgerTransactionType::ReferralRewardCredit->value)
        ->count();

    $commissions = ReferralCommission::query()->where('source_account_id', $accountId)->get();

    expect(ReferralQualifyingEvent::query()->where('source_account_id', $accountId)->count())->toBe(1)
        ->and($commissions)->toHaveCount(2)
        ->and($commissions->every(fn (ReferralCommission $commission) => $commission->status === CommissionStatus::Paid))->toBeTrue()
        ->and($credits($this->direct))->toBe(1)
        ->and($credits($this->second))->toBe(1)
        ->and((string) DB::table('wallets')->where('business_account_id', $this->direct->id)->value('total'))->toBe('650.00')
        ->and((string) DB::table('wallets')->where('business_account_id', $this->second->id)->value('total'))->toBe('325.00');
});
