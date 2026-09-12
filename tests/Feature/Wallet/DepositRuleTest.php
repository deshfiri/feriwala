<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Enums\DepositFrequency;
use App\Domain\Wallet\Models\DepositRule;
use App\Domain\Wallet\Queries\ResolveDepositRule;
use App\Support\Rules\RuleScope;
use Carbon\CarbonImmutable;

/*
 * Deposit rules and which one wins (P2-11, §24.1).
 *
 * §24.1 lets a deposit be set globally, per package, per user, and per website,
 * domain or hosting service. Six subjects, one answer — and the answer has to be
 * the same on every server, so the precedence is the shared RuleResolver rather
 * than anything invented here.
 */

function depositRule(array $attributes = []): DepositRule
{
    return DepositRule::create([
        'scope' => RuleScope::Global,
        'scope_id' => null,
        'required_initial_deposit_minor' => 500000,
        'minimum_balance_minor' => 200000,
        'currency_code' => 'BDT',
        'effective_from' => CarbonImmutable::now()->subMonth(),
        'is_active' => true,
        ...$attributes,
    ]);
}

describe('what a rule holds', function () {
    it('keeps §24.1 figures as money in minor units', function () {
        $rule = depositRule([
            'required_top_up_minor' => 100000,
            'low_balance_threshold_minor' => 250000,
            'critical_balance_threshold_minor' => 150000,
        ]);

        expect($rule->required_initial_deposit_minor->minorUnits)->toBe(500000)
            ->and($rule->minimum_balance_minor->minorUnits)->toBe(200000)
            ->and($rule->required_top_up_minor->minorUnits)->toBe(100000)
            ->and($rule->low_balance_threshold_minor->minorUnits)->toBe(250000)
            ->and($rule->critical_balance_threshold_minor->minorUnits)->toBe(150000)
            ->and($rule->frequency)->toBe(DepositFrequency::OneTime);
    });

    it('falls back to the minimum balance when no critical threshold is set', function () {
        /*
         * Falling below what you are required to keep is the condition §24.3
         * acts on, whether or not somebody configured a second figure for it.
         */
        $rule = depositRule(['critical_balance_threshold_minor' => null]);

        expect($rule->criticalFloor()->minorUnits)->toBe(200000)
            ->and($rule->lowFloor())->toBeNull();
    });

    it('treats a rule of all zeroes as a real answer', function () {
        // How an administrator says "this package requires no deposit" and
        // overrides a global rule that does.
        $rule = depositRule([
            'required_initial_deposit_minor' => 0,
            'minimum_balance_minor' => 0,
        ]);

        expect($rule->requiresAnything())->toBeFalse();
    });

    it('starts with every restriction switched off', function () {
        // A deposit requirement that disabled accounts the moment it was
        // created would be a policy nobody chose.
        $rule = depositRule();

        expect($rule->restricts_chargeable_services)->toBeFalse()
            ->and($rule->pauses_website_setup)->toBeFalse()
            ->and($rule->disables_website)->toBeFalse()
            ->and($rule->restricts_account)->toBeFalse()
            ->and($rule->disables_account)->toBeFalse()
            // Except restoration, which is on: money arriving should put
            // services back without anybody having to ask (§24.3).
            ->and($rule->restores_automatically)->toBeTrue();
    });
});

describe('precedence', function () {
    beforeEach(function () {
        $this->account = testAccountWithStaffLimit(5, AccountStatus::Active);
        $this->packageId = $this->account->currentPackage->package_id;
    });

    it('falls back to the global rule when nothing narrower exists', function () {
        $global = depositRule();

        expect(app(ResolveDepositRule::class)->for($this->account)?->id)->toBe($global->id);
    });

    it('lets a package rule beat the global one', function () {
        depositRule();

        $package = depositRule([
            'scope' => RuleScope::Package,
            'scope_id' => $this->packageId,
            'minimum_balance_minor' => 300000,
        ]);

        expect(app(ResolveDepositRule::class)->for($this->account)?->id)->toBe($package->id);
    });

    it('lets a rule for this account beat its package', function () {
        // §27.3's reading, applied here: a deposit agreed with one business
        // overrides what its plan says.
        depositRule();
        depositRule(['scope' => RuleScope::Package, 'scope_id' => $this->packageId]);

        $mine = depositRule([
            'scope' => RuleScope::User,
            'scope_id' => $this->account->id,
            'minimum_balance_minor' => 50000,
        ]);

        expect(app(ResolveDepositRule::class)->for($this->account)?->id)->toBe($mine->id);
    });

    it('ignores a rule written for somebody else', function () {
        // Not a weaker match — simply not applicable.
        $other = testBusinessAccount(AccountStatus::Active);
        $global = depositRule();

        depositRule(['scope' => RuleScope::User, 'scope_id' => $other->id]);

        expect(app(ResolveDepositRule::class)->for($this->account)?->id)->toBe($global->id);
    });

    it('ignores a rule whose window has closed', function () {
        $global = depositRule();

        depositRule([
            'scope' => RuleScope::User,
            'scope_id' => $this->account->id,
            'effective_from' => CarbonImmutable::now()->subYear(),
            'effective_until' => CarbonImmutable::now()->subMonth(),
        ]);

        expect(app(ResolveDepositRule::class)->for($this->account)?->id)->toBe($global->id);
    });

    it('ignores a rule that has not started yet', function () {
        $global = depositRule();

        depositRule([
            'scope' => RuleScope::User,
            'scope_id' => $this->account->id,
            'effective_from' => CarbonImmutable::now()->addWeek(),
        ]);

        expect(app(ResolveDepositRule::class)->for($this->account)?->id)->toBe($global->id);
    });

    it('answers for a date in the past as the past would have', function () {
        /*
         * What reproducibility rests on. A rule that took over last week must
         * not change what an obligation captured a month ago was resolved from.
         */
        $old = depositRule([
            'effective_from' => CarbonImmutable::now()->subMonths(6),
            'effective_until' => CarbonImmutable::now()->subWeek(),
            'minimum_balance_minor' => 100000,
        ]);

        depositRule(['effective_from' => CarbonImmutable::now()->subWeek()]);

        $resolved = app(ResolveDepositRule::class)
            ->for($this->account, CarbonImmutable::now()->subMonth());

        expect($resolved?->id)->toBe($old->id)
            ->and($resolved?->minimum_balance_minor->minorUnits)->toBe(100000);
    });

    it('ignores a rule somebody switched off', function () {
        $global = depositRule();

        depositRule([
            'scope' => RuleScope::User,
            'scope_id' => $this->account->id,
            'is_active' => false,
        ]);

        expect(app(ResolveDepositRule::class)->for($this->account)?->id)->toBe($global->id);
    });

    it('breaks a tie by priority rather than by luck', function () {
        // Without a deterministic tie-break the same account could resolve
        // differently on two servers.
        depositRule(['priority' => 0]);
        $winner = depositRule(['priority' => 10]);

        expect(app(ResolveDepositRule::class)->for($this->account)?->id)->toBe($winner->id);
    });

    it('says nothing when no rule is configured at all', function () {
        // Not zero, and not a default invented here: no deposit policy exists.
        expect(app(ResolveDepositRule::class)->for($this->account))->toBeNull();
    });

    it('shows what won and what it beat', function () {
        depositRule();
        depositRule(['scope' => RuleScope::Package, 'scope_id' => $this->packageId]);
        depositRule(['scope' => RuleScope::User, 'scope_id' => $this->account->id]);

        $applicable = app(ResolveDepositRule::class)->applicable($this->account);

        expect($applicable)->toHaveCount(3)
            ->and($applicable[0]->scope)->toBe(RuleScope::User)
            ->and($applicable[1]->scope)->toBe(RuleScope::Package)
            ->and($applicable[2]->scope)->toBe(RuleScope::Global);
    });

    it('lets a service-specific rule beat the package but not the account', function () {
        /*
         * Website, domain and hosting are narrower than a package and wider
         * than a deal struck with the business itself. The modules are not
         * built; the scopes resolve the moment an id is passed in.
         */
        depositRule(['scope' => RuleScope::Package, 'scope_id' => $this->packageId]);
        $hosting = depositRule(['scope' => RuleScope::Hosting, 'scope_id' => 77]);

        $resolved = app(ResolveDepositRule::class)
            ->for($this->account, hostingId: 77);

        expect($resolved?->id)->toBe($hosting->id);

        $mine = depositRule(['scope' => RuleScope::User, 'scope_id' => $this->account->id]);

        expect(app(ResolveDepositRule::class)->for($this->account, hostingId: 77)?->id)
            ->toBe($mine->id);
    });
});
