<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Package\Models\Package;
use App\Domain\Referral\Actions\CloseReferralPlan;
use App\Domain\Referral\Data\RewardRule;
use App\Domain\Referral\Enums\ReferralTrigger;
use App\Domain\Referral\Enums\RewardType;
use App\Domain\Referral\Exceptions\ReferralRefused;
use App\Domain\Referral\Models\ReferralPlan;
use App\Domain\Referral\Queries\ResolveReferralPlan;
use App\Domain\Referral\ReferralSettings;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Multi-level plan versions and their arithmetic (§25.4.1, D24, P7-12).
 */
describe('the reward arithmetic', function () {
    it('pays a fixed amount, or a percentage rounded down to the smallest unit', function () {
        $tenPercent = new RewardRule(RewardType::Percentage, rateBps: 1000);
        $fixed = new RewardRule(RewardType::Fixed, amount: Money::fromDecimal('100.00'));

        expect($tenPercent->amountFor(Money::fromDecimal('6500.00'))->toDecimal())->toBe('650.00')
            // 10% of 123.45 is 12.345 — rounded down, never up.
            ->and($tenPercent->amountFor(Money::fromDecimal('123.45'))->toDecimal())->toBe('12.34')
            ->and((new RewardRule(RewardType::Percentage, rateBps: 333))->amountFor(Money::fromDecimal('999.99'))->toDecimal())->toBe('33.29')
            ->and($fixed->amountFor(Money::fromDecimal('6500.00'))->toDecimal())->toBe('100.00');
    });

    it('never pays above its cap or above the base itself', function () {
        expect((new RewardRule(RewardType::Percentage, rateBps: 5000, cap: Money::fromDecimal('200.00')))->amountFor(Money::fromDecimal('6500.00'))->toDecimal())->toBe('200.00')
            ->and((new RewardRule(RewardType::Fixed, amount: Money::fromDecimal('9000.00')))->amountFor(Money::fromDecimal('6500.00'))->toDecimal())->toBe('6500.00')
            ->and((new RewardRule(RewardType::Percentage, rateBps: 1000))->amountFor(Money::fromDecimal('-5.00'))->toDecimal())->toBe('0.00');
    });

    it('reads a percentage typed as text into basis points without a float', function (string $typed, ?int $basisPoints) {
        expect(RewardRule::basisPointsFromPercent($typed))->toBe($basisPoints);
    })->with([
        ['10', 1000],
        ['2.5', 250],
        ['0.01', 1],
        ['100', 10000],
        ['12.34', 1234],
        ['100.01', null],
        ['0', null],
        ['1.234', null],
        ['1e2', null],
        ['-5', null],
    ]);

    it('refuses a malformed or unsupported rule', function (string $type, ?string $amount, ?int $rate, ?string $cap) {
        expect(fn () => new RewardRule(RewardType::from($type), $amount === null ? null : Money::fromDecimal($amount), $rate, $cap === null ? null : Money::fromDecimal($cap)))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'zero fixed' => ['fixed', '0', null, null],
        'negative fixed' => ['fixed', '-1.00', null, null],
        'percentage above whole' => ['percentage', null, 10001, null],
        'percentage with an amount' => ['percentage', '1.00', 100, null],
        'zero cap' => ['fixed', '1.00', null, '0'],
    ]);
});

describe('plan versions', function () {
    it('opens a version with a rule for every level up to its depth, audited', function () {
        $plan = referralTestPlan([['percentage', '10'], ['percentage', '5'], ['fixed', '100.00']]);

        expect($plan->max_depth)->toBe(3)
            ->and($plan->levels->pluck('level')->all())->toBe([1, 2, 3])
            ->and($plan->levels[0]->rate_bps)->toBe(1000)
            ->and($plan->levels[2]->amount->toDecimal())->toBe('100.00');

        $audit = AuditLog::query()->where('action', 'referral.plan_opened')->firstOrFail();

        expect($audit->after['max_depth'])->toBe(3)
            ->and($audit->after['levels'][1]['rate_bps'])->toBe(500)
            ->and($audit->reason)->toBe('Plan for a test.');
    });

    it('refuses a version whose levels do not cover every level up to its depth', function () {
        expect(fn () => referralTestPlan([['percentage', '10']], ['maxDepth' => 3]))
            ->toThrow(ReferralRefused::class, __('referral.refused.levels_incomplete', ['depth' => 3]));

        expect(ReferralPlan::query()->count())->toBe(0);
    });

    it('ends the version in force where the next one begins', function () {
        $first = referralTestPlan([['percentage', '10']], ['effectiveFrom' => now()->subDay()->toImmutable()]);
        $second = referralTestPlan([['percentage', '8'], ['percentage', '4']]);

        expect($first->refresh()->effective_to?->equalTo($second->effective_from))->toBeTrue()
            ->and(AuditLog::query()->where('action', 'referral.plan_superseded')->exists())->toBeTrue();

        $resolver = app(ResolveReferralPlan::class);

        expect($resolver->for(ReferralTrigger::AccountActivation, null, now()->subHours(2)->toImmutable())?->id)->toBe($first->id)
            ->and($resolver->for(ReferralTrigger::AccountActivation, null, now()->toImmutable())?->id)->toBe($second->id);
    });

    it('refuses to open a version over one already scheduled', function () {
        referralTestPlan([['percentage', '10']], ['effectiveFrom' => now()->addDays(3)->toImmutable()]);

        expect(fn () => referralTestPlan([['percentage', '8']], ['effectiveFrom' => now()->addDay()->toImmutable()]))
            ->toThrow(ReferralRefused::class, __('referral.refused.plan_overlaps'));
    });

    it('resolves a package\'s own version before the global default, and falls back to it', function () {
        $package = Package::create(['slug' => 'gold', 'name' => 'Gold', 'fee' => Money::fromDecimal('9000.00'), 'currency_code' => 'BDT', 'is_active' => true]);
        $other = Package::create(['slug' => 'silver', 'name' => 'Silver', 'fee' => Money::fromDecimal('5000.00'), 'currency_code' => 'BDT', 'is_active' => true]);

        $global = referralTestPlan([['percentage', '10']]);
        $gold = referralTestPlan([['percentage', '15'], ['percentage', '5']], ['packageId' => $package->id]);

        $resolver = app(ResolveReferralPlan::class);
        $now = now()->toImmutable();

        expect($resolver->for(ReferralTrigger::AccountActivation, $package->id, $now)?->id)->toBe($gold->id)
            ->and($resolver->for(ReferralTrigger::AccountActivation, $other->id, $now)?->id)->toBe($global->id)
            ->and($resolver->for(ReferralTrigger::AccountActivation, null, $now)?->id)->toBe($global->id);
    });

    it('closes a version with a reason, and never deletes or edits one', function () {
        $plan = referralTestPlan([['percentage', '10'], ['fixed', '100.00']]);
        $admin = User::factory()->create();

        app(CloseReferralPlan::class)->handle($plan, $admin, 'Suspending the programme for review.');

        expect($plan->refresh()->closed_at)->not->toBeNull()
            ->and($plan->close_reason)->toBe('Suspending the programme for review.')
            ->and(app(ResolveReferralPlan::class)->for(ReferralTrigger::AccountActivation, null, now()->addMinute()->toImmutable()))->toBeNull();

        expect(fn () => app(CloseReferralPlan::class)->handle($plan, $admin, 'Again.'))
            ->toThrow(ReferralRefused::class, __('referral.refused.plan_not_open'));

        expect(fn () => DB::transaction(fn () => DB::table('referral_plans')->where('id', $plan->id)->update(['max_depth' => 9])))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('referral_plan_levels')->where('referral_plan_id', $plan->id)->update(['rate_bps' => 9000])))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::transaction(fn () => DB::table('referral_plans')->where('id', $plan->id)->delete()))
            ->toThrow(QueryException::class);
    });
});

describe('the global switch', function () {
    it('is off until someone switches it on, and every switch is audited', function () {
        $settings = app(ReferralSettings::class);
        $admin = User::factory()->create();

        expect($settings->enabled())->toBeFalse();

        $settings->switchTo(true, $admin, 'Launching the programme.');

        expect(app(ReferralSettings::class)->enabled())->toBeTrue();

        $audit = AuditLog::query()->where('action', 'referral.mlm_switched')->firstOrFail();

        expect($audit->before)->toBe(['enabled' => false])
            ->and($audit->after)->toBe(['enabled' => true])
            ->and($audit->actor_id)->toBe($admin->id)
            ->and($audit->reason)->toBe('Launching the programme.');
    });
});
