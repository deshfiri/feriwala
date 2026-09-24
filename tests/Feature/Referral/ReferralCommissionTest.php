<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Package\Models\Package;
use App\Domain\Referral\Actions\CalculateReferralCommissions;
use App\Domain\Referral\Actions\CloseReferralPlan;
use App\Domain\Referral\Actions\ReleaseDueReferralCommissions;
use App\Domain\Referral\Actions\ReleaseReferralCommission;
use App\Domain\Referral\Actions\ReverseQualifyingEvent;
use App\Domain\Referral\Actions\ReverseReferralCommission;
use App\Domain\Referral\Data\RewardRule;
use App\Domain\Referral\Enums\CommissionBase;
use App\Domain\Referral\Enums\CommissionSkipReason;
use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Enums\ReversalCause;
use App\Domain\Referral\Enums\RewardType;
use App\Domain\Referral\Exceptions\ReferralRefused;
use App\Domain\Referral\Jobs\ReleaseReferralCommissions;
use App\Domain\Referral\Models\AccountReferral;
use App\Domain\Referral\Models\ReferralCommission;
use App\Domain\Referral\Models\ReferralQualifyingEvent;
use App\Domain\Referral\ReferralSettings;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\WalletService;
use App\Models\User;
use App\Notifications\Referral\ReferralCommissionPaid;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The multi-level commission engine and its money (D24, P7-42, P7-43).
 *
 * Activation fees of 6 500.00 taka unless a test says otherwise, so 10% is
 * 650.00 and 5% is 325.00.
 */
beforeEach(function () {
    referralTestSwitchOn();
    Notification::fake();
});

/**
 * The commissions of the one event, level => row.
 *
 * @return array<int, ReferralCommission>
 */
function referralCommissionsByLevel(BusinessAccount $source): array
{
    return ReferralCommission::query()
        ->where('source_account_id', $source->id)
        ->orderBy('level')
        ->get()
        ->keyBy('level')
        ->all();
}

/**
 * What a business's wallet has been credited for referrals, as exact Taka.
 */
function referralCredited(BusinessAccount $account): Money
{
    $wallet = Wallet::query()->where('business_account_id', $account->id)->first();

    if ($wallet === null) {
        return Money::zero();
    }

    return Money::fromDecimal((string) LedgerEntry::query()
        ->where('wallet_id', $wallet->id)
        ->whereIn('type', [LedgerTransactionType::ReferralRewardCredit->value, LedgerTransactionType::JoiningRewardCredit->value])
        ->sum('credit'));
}

describe('traversal and calculation', function () {
    it('pays each level its own rule, fixed or percentage, up to the depth and no further', function () {
        [$fifth, $fourth, $third, $second, $direct] = referralTestChain(5);
        referralTestPlan([['percentage', '10'], ['percentage', '5'], ['fixed', '100.00']]);

        $newcomer = referralTestActivate(referralTestNewcomer($direct));

        $levels = referralCommissionsByLevel($newcomer);

        expect(array_keys($levels))->toBe([1, 2, 3])
            ->and($levels[1]->beneficiary_account_id)->toBe($direct->id)
            ->and($levels[1]->amount->toDecimal())->toBe('650.00')
            ->and($levels[2]->beneficiary_account_id)->toBe($second->id)
            ->and($levels[2]->amount->toDecimal())->toBe('325.00')
            ->and($levels[3]->beneficiary_account_id)->toBe($third->id)
            ->and($levels[3]->amount->toDecimal())->toBe('100.00')
            ->and(collect($levels)->every(fn (ReferralCommission $c) => $c->status === CommissionStatus::Paid))->toBeTrue()
            // Beyond the depth nobody is paid.
            ->and(referralCredited($fourth)->isZero())->toBeTrue()
            ->and(referralCredited($fifth)->isZero())->toBeTrue()
            ->and(referralCredited($direct)->toDecimal())->toBe('650.00')
            ->and(referralCredited($second)->toDecimal())->toBe('325.00')
            ->and(referralCredited($third)->toDecimal())->toBe('100.00');

        Notification::assertSentTo($direct->owner, ReferralCommissionPaid::class);
    });

    it('records the missing levels of a short chain and pays nobody for them', function () {
        [$top, $direct] = referralTestChain(2);
        referralTestPlan([['percentage', '10'], ['percentage', '5'], ['percentage', '2'], ['percentage', '1']]);

        $newcomer = referralTestActivate(referralTestNewcomer($direct));
        $event = ReferralQualifyingEvent::query()->where('source_account_id', $newcomer->id)->firstOrFail();

        expect(array_keys(referralCommissionsByLevel($newcomer)))->toBe([1, 2])
            ->and(array_column($event->chain, 'outcome'))->toBe(['pending', 'pending', 'missing', 'missing']);
    });

    it('never shifts a deeper ancestor into a switched-off or unqualified level', function () {
        [$third, $second, $direct] = referralTestChain(3);
        $second->forceFill(['status' => AccountStatus::Suspended])->save();

        referralTestPlan([['percentage', '10'], ['percentage', '5'], ['fixed', '100.00']], [
            'levels' => [
                ['level' => 1, 'rule' => new RewardRule(RewardType::Percentage, rateBps: 1000), 'enabled' => false, 'required_package_ids' => [], 'min_active_direct_referrals' => 0],
                ['level' => 2, 'rule' => new RewardRule(RewardType::Percentage, rateBps: 500), 'enabled' => true, 'required_package_ids' => [], 'min_active_direct_referrals' => 0],
                ['level' => 3, 'rule' => new RewardRule(RewardType::Fixed, amount: Money::fromDecimal('100.00')), 'enabled' => true, 'required_package_ids' => [], 'min_active_direct_referrals' => 0],
            ],
        ]);

        $newcomer = referralTestActivate(referralTestNewcomer($direct));
        $levels = referralCommissionsByLevel($newcomer);

        expect($levels[1]->status)->toBe(CommissionStatus::Skipped)
            ->and($levels[1]->skip_reason)->toBe(CommissionSkipReason::LevelDisabled)
            ->and($levels[2]->beneficiary_account_id)->toBe($second->id)
            ->and($levels[2]->skip_reason)->toBe(CommissionSkipReason::StatusNotQualified)
            // The third ancestor keeps level 3 and its fixed reward.
            ->and($levels[3]->beneficiary_account_id)->toBe($third->id)
            ->and($levels[3]->amount->toDecimal())->toBe('100.00')
            ->and($levels[3]->status)->toBe(CommissionStatus::Paid)
            ->and(referralCredited($direct)->isZero())->toBeTrue()
            ->and(referralCredited($second)->isZero())->toBeTrue();
    });

    it('pays a suspended ancestor only under a version that says so', function () {
        [$direct] = referralTestChain(1);
        $direct->forceFill(['status' => AccountStatus::Suspended])->save();
        referralTestPlan([['percentage', '10']], ['qualifiesSuspended' => true]);

        $newcomer = referralTestActivate(referralTestNewcomer($direct));

        expect(referralCommissionsByLevel($newcomer)[1]->status)->toBe(CommissionStatus::Paid)
            ->and(referralCredited($direct)->toDecimal())->toBe('650.00');
    });

    it('asks each level for its packages and its active direct referrals', function () {
        [$second, $direct] = referralTestChain(2);
        $gold = Package::create(['slug' => 'gold', 'name' => 'Gold', 'fee' => Money::fromDecimal('9000.00'), 'currency_code' => 'BDT', 'is_active' => true]);

        $rule = fn (string $percent) => new RewardRule(RewardType::Percentage, rateBps: RewardRule::basisPointsFromPercent($percent));

        referralTestPlan([['percentage', '10'], ['percentage', '5']], ['levels' => [
            ['level' => 1, 'rule' => $rule('10'), 'enabled' => true, 'required_package_ids' => [$gold->id], 'min_active_direct_referrals' => 0],
            ['level' => 2, 'rule' => $rule('5'), 'enabled' => true, 'required_package_ids' => [], 'min_active_direct_referrals' => 3],
        ]]);

        $newcomer = referralTestActivate(referralTestNewcomer($direct));
        $levels = referralCommissionsByLevel($newcomer);

        // The direct referrer holds no Gold package; the second has one active
        // direct referral, not three.
        expect($levels[1]->skip_reason)->toBe(CommissionSkipReason::PackageNotEligible)
            ->and($levels[2]->skip_reason)->toBe(CommissionSkipReason::TooFewDirectReferrals);
    });

    it('resolves the account\'s package version first and falls back to the global default', function () {
        [$direct] = referralTestChain(1);
        $gold = Package::create(['slug' => 'gold', 'name' => 'Gold', 'fee' => Money::fromDecimal('9000.00'), 'currency_code' => 'BDT', 'is_active' => true]);

        referralTestPlan([['percentage', '10']]);
        referralTestPlan([['percentage', '20']], ['packageId' => $gold->id]);

        $onGold = referralTestActivate(referralTestNewcomer($direct, $gold));
        $withoutPackage = referralTestActivate(referralTestNewcomer($direct));

        expect(referralCommissionsByLevel($onGold)[1]->amount->toDecimal())->toBe('1300.00')
            ->and(referralCommissionsByLevel($withoutPackage)[1]->amount->toDecimal())->toBe('650.00');
    });

    it('calculates in exact Taka, rounding down, net of a discount shared in proportion', function () {
        [$direct] = referralTestChain(1);
        referralTestPlan([['percentage', '10']], ['base' => CommissionBase::PackageFee]);

        // Package fee 5000.00 of 6500.00 revenue carries ceil(5000.00 × 100.01 ÷ 6500.00)
        // = 76.94 of the discount: a base of 4923.06, and 10% of it is 492.306.
        $newcomer = referralTestActivate(referralTestNewcomer($direct, discount: '100.01'));

        $level = referralCommissionsByLevel($newcomer)[1];

        expect($level->commission_base->toDecimal())->toBe('4923.06')
            ->and($level->amount->toDecimal())->toBe('492.30')
            ->and(referralCredited($direct)->toDecimal())->toBe('492.30');
    });

    it('never lets the chain pay more than the base', function () {
        [$second, $direct] = referralTestChain(2);
        referralTestPlan([['percentage', '60'], ['percentage', '60']], [
            'joiningReward' => new RewardRule(RewardType::Fixed, amount: Money::fromDecimal('50.00')),
        ]);

        $newcomer = referralTestActivate(referralTestNewcomer($direct));
        $levels = referralCommissionsByLevel($newcomer);

        expect($levels[1]->amount->toDecimal())->toBe('3900.00')
            ->and($levels[2]->amount->toDecimal())->toBe('2600.00')
            ->and($levels[2]->capped)->toBeTrue()
            ->and($levels[0]->status)->toBe(CommissionStatus::Skipped)
            ->and($levels[0]->skip_reason)->toBe(CommissionSkipReason::BaseExhausted)
            ->and(collect($levels)->reduce(fn (Money $sum, ReferralCommission $c) => $sum->plus($c->amount), Money::zero())->toDecimal())->toBe('6500.00');
    });

    it('pays the new account its joining reward only when it was referred', function () {
        [$direct] = referralTestChain(1);
        referralTestPlan([['percentage', '10']], [
            'joiningReward' => new RewardRule(RewardType::Fixed, amount: Money::fromDecimal('50.00')),
        ]);

        $referred = referralTestActivate(referralTestNewcomer($direct));
        $unreferred = referralTestActivate(referralTestNewcomer(null));

        $joining = referralCommissionsByLevel($referred)[0];

        expect($joining->beneficiary_account_id)->toBe($referred->id)
            ->and($joining->amount->toDecimal())->toBe('50.00')
            ->and((string) LedgerEntry::query()->where('type', LedgerTransactionType::JoiningRewardCredit->value)->sum('credit'))->toBe('50.00')
            ->and(ReferralCommission::query()->where('source_account_id', $unreferred->id)->exists())->toBeFalse();
    });

    it('pays nothing while the programme is switched off or no version is in force', function () {
        [$direct] = referralTestChain(1);

        $noPlan = referralTestActivate(referralTestNewcomer($direct));

        referralTestPlan([['percentage', '10']]);
        app(ReferralSettings::class)->switchTo(false, User::factory()->create(), 'Paused for a test.');

        $switchedOff = referralTestActivate(referralTestNewcomer($direct));

        expect(ReferralQualifyingEvent::query()->whereIn('source_account_id', [$noPlan->id, $switchedOff->id])->exists())->toBeFalse()
            ->and(referralCredited($direct)->isZero())->toBeTrue();
    });
});

describe('the ledger and the outbox', function () {
    it('pays each commission as an immutable ledger entry carrying its own key, once', function () {
        [$direct] = referralTestChain(1);
        referralTestPlan([['percentage', '10']]);

        $newcomer = referralTestActivate(referralTestNewcomer($direct));
        $commission = referralCommissionsByLevel($newcomer)[1];

        // A retry of the release, the job and the sweep all find nothing to pay.
        app(ReleaseReferralCommission::class)->handle($commission->id);
        ReleaseReferralCommissions::dispatchSync($commission->referral_qualifying_event_id);
        app(ReleaseDueReferralCommissions::class)->handle();

        $transaction = WalletTransaction::query()->findOrFail($commission->refresh()->wallet_transaction_id);

        expect($transaction->idempotency_key)->toBe('referral-commission:'.$commission->public_id)
            ->and(WalletTransaction::query()->where('idempotency_key', 'like', 'referral-commission:%')->count())->toBe(1)
            ->and(referralCredited($direct)->toDecimal())->toBe('650.00');

        expect(fn () => DB::transaction(fn () => DB::table('ledger_entries')->where('wallet_transaction_id', $transaction->id)->update(['credit' => 1])))
            ->toThrow(QueryException::class);
    });

    it('records one event per activation however often it is asked', function () {
        [$direct] = referralTestChain(1);
        referralTestPlan([['percentage', '10']]);

        $newcomer = referralTestActivate(referralTestNewcomer($direct));
        $again = app(CalculateReferralCommissions::class)->forActivation($newcomer);

        expect(ReferralQualifyingEvent::query()->where('source_account_id', $newcomer->id)->count())->toBe(1)
            ->and($again?->source_account_id)->toBe($newcomer->id)
            ->and(ReferralCommission::query()->where('source_account_id', $newcomer->id)->count())->toBe(1);
    });

    it('holds a commission for the holding period, then the sweep pays it', function () {
        [$direct] = referralTestChain(1);
        referralTestPlan([['percentage', '10']], ['holdingDays' => 7]);

        $newcomer = referralTestActivate(referralTestNewcomer($direct));

        expect(referralCommissionsByLevel($newcomer)[1]->status)->toBe(CommissionStatus::Pending)
            ->and(referralCredited($direct)->isZero())->toBeTrue();

        $this->travel(8)->days();

        expect(app(ReleaseDueReferralCommissions::class)->handle()['released'])->toBe(1)
            ->and(referralCredited($direct)->toDecimal())->toBe('650.00');
    });

    it('waits while a beneficiary may not be paid, and cancels for one that closed', function () {
        [$second, $direct] = referralTestChain(2);
        referralTestPlan([['percentage', '10'], ['percentage', '5']], ['holdingDays' => 1]);

        $newcomer = referralTestActivate(referralTestNewcomer($direct));

        $direct->forceFill(['status' => AccountStatus::Suspended])->save();
        $second->forceFill(['status' => AccountStatus::Closed])->save();
        $this->travel(2)->days();
        app(ReleaseDueReferralCommissions::class)->handle();

        $levels = referralCommissionsByLevel($newcomer);

        expect($levels[1]->status)->toBe(CommissionStatus::Pending)
            ->and($levels[2]->status)->toBe(CommissionStatus::Cancelled)
            ->and($levels[2]->reversal_cause)->toBe(ReversalCause::BeneficiaryClosed);

        $direct->forceFill(['status' => AccountStatus::Active])->save();
        app(ReleaseDueReferralCommissions::class)->handle();

        expect($levels[1]->refresh()->status)->toBe(CommissionStatus::Paid);
    });

    it('locks every referral the chain was read through', function () {
        [$top, $second, $direct] = referralTestChain(3);
        referralTestPlan([['percentage', '10'], ['percentage', '5']]);

        $newcomer = referralTestActivate(referralTestNewcomer($direct));

        $locked = fn (BusinessAccount $account) => AccountReferral::query()->where('referred_account_id', $account->id)->value('locked_at') !== null;

        expect($locked($newcomer))->toBeTrue()
            ->and($locked($direct))->toBeTrue()
            // Read no further than level 2: the second's own link was not used.
            ->and($locked($second))->toBeFalse();
    });

    it('keeps the rule it was calculated under when the plan changes afterwards', function () {
        [$direct] = referralTestChain(1);
        $plan = referralTestPlan([['percentage', '10']]);

        $first = referralTestActivate(referralTestNewcomer($direct));

        app(CloseReferralPlan::class)->handle($plan, User::factory()->create(), 'Changing the rates for a test.');
        referralTestPlan([['percentage', '3']], ['effectiveFrom' => now()->toImmutable()]);

        $second = referralTestActivate(referralTestNewcomer($direct));

        $old = referralCommissionsByLevel($first)[1]->refresh();

        expect($old->referral_plan_id)->toBe($plan->id)
            ->and($old->rule_snapshot['rate_bps'])->toBe(1000)
            ->and($old->amount->toDecimal())->toBe('650.00')
            ->and(referralCommissionsByLevel($second)[1]->amount->toDecimal())->toBe('195.00');

        expect(fn () => DB::transaction(fn () => DB::table('referral_commissions')->where('id', $old->id)->update(['amount' => 1])))
            ->toThrow(QueryException::class);
    });
});

describe('reversal', function () {
    it('takes back a paid chain by compensating entries when the event is reversed', function () {
        [$second, $direct] = referralTestChain(2);
        referralTestPlan([['percentage', '10'], ['percentage', '5']], ['holdingDays' => 0]);

        $newcomer = referralTestActivate(referralTestNewcomer($direct));
        $event = ReferralQualifyingEvent::query()->where('source_account_id', $newcomer->id)->firstOrFail();

        app(ReverseQualifyingEvent::class)->handle($event, ReversalCause::Refund, 'Refund of the activation payment.');

        $levels = referralCommissionsByLevel($newcomer);
        $reversal = LedgerEntry::query()->where('wallet_transaction_id', $levels[1]->reversal_wallet_transaction_id)->firstOrFail();

        expect($event->refresh()->status)->toBe(ReferralQualifyingEvent::REVERSED)
            ->and($levels[1]->status)->toBe(CommissionStatus::Reversed)
            ->and($levels[2]->status)->toBe(CommissionStatus::Reversed)
            ->and($reversal->debit->toDecimal())->toBe('650.00')
            ->and($reversal->type)->toBe(LedgerTransactionType::ReferralRewardReversal)
            // The credit it answers is still there, unchanged.
            ->and(LedgerEntry::query()->where('wallet_transaction_id', $levels[1]->wallet_transaction_id)->firstOrFail()->credit->toDecimal())->toBe('650.00')
            ->and(Wallet::query()->where('business_account_id', $direct->id)->firstOrFail()->total->toDecimal())->toBe('0.00')
            ->and(AuditLog::query()->where('action', 'referral.event_reversed')->exists())->toBeTrue();
    });

    it('cancels a commission not yet paid without touching the ledger', function () {
        [$direct] = referralTestChain(1);
        referralTestPlan([['percentage', '10']], ['holdingDays' => 30]);

        $newcomer = referralTestActivate(referralTestNewcomer($direct));
        $commission = referralCommissionsByLevel($newcomer)[1];

        app(ReverseReferralCommission::class)->handle($commission, ReversalCause::Fraud, 'Duplicate account abuse.', User::factory()->create());

        expect($commission->status)->toBe(CommissionStatus::Cancelled)
            ->and(LedgerEntry::query()->count())->toBe(0);

        expect(fn () => app(ReverseReferralCommission::class)->handle($commission, ReversalCause::Fraud, 'Again.'))
            ->toThrow(ReferralRefused::class);
    });

    it('owes a reversal the wallet cannot carry, and posts it once the wallet can', function () {
        [$direct] = referralTestChain(1);
        referralTestPlan([['percentage', '10']]);

        $newcomer = referralTestActivate(referralTestNewcomer($direct));
        $commission = referralCommissionsByLevel($newcomer)[1];
        $wallet = Wallet::query()->where('business_account_id', $direct->id)->firstOrFail();

        // The commission has been spent.
        app(WalletService::class)->debit($wallet, LedgerTransactionType::ServiceFeeDebit, Money::fromDecimal('600.00', Currency::BDT), new PostingContext(source: 'test', description: 'Spent'));

        app(ReverseReferralCommission::class)->handle($commission, ReversalCause::Chargeback, 'Chargeback on the activation payment.', User::factory()->create());

        expect($commission->status)->toBe(CommissionStatus::ReversalOwed)
            ->and($wallet->refresh()->total->toDecimal())->toBe('50.00');

        app(WalletService::class)->credit($wallet, LedgerTransactionType::TopUpCredit, Money::fromDecimal('1000.00', Currency::BDT), new PostingContext(source: 'test', description: 'Top-up'));

        expect(app(ReleaseDueReferralCommissions::class)->handle()['recovered'])->toBe(1)
            ->and($commission->refresh()->status)->toBe(CommissionStatus::Reversed)
            ->and($commission->reversal_cause)->toBe(ReversalCause::Chargeback)
            ->and($wallet->refresh()->total->toDecimal())->toBe('400.00');
    });
});
