<?php

namespace App\Domain\Wallet\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wallet\Models\DepositRule;
use App\Support\Rules\RuleContext;
use App\Support\Rules\RuleResolver;
use App\Support\Rules\RuleScope;
use Carbon\CarbonImmutable;

/**
 * Which deposit rule governs this account, at this moment (§24.1).
 *
 * One question with one answer, and the answer has to be the same on every
 * server and in every process — so the precedence is not decided here. It is
 * {@see RuleResolver}, shared with commission, withdrawal and referral rules:
 * most specific scope wins, then explicit priority, then the most recently
 * effective rule, then the identifier as a final deterministic tie-break.
 *
 * What this class does is build the context §24.1 asks for: the account, the
 * package it is on, and — when the modules exist — the website, domain or
 * hosting service the decision is being made about. A scope with nothing in the
 * context simply never matches, so the three unbuilt ones cost nothing and open
 * on their own the day they are passed in.
 */
class ResolveDepositRule
{
    public function __construct(
        protected RuleResolver $resolver,
    ) {}

    /**
     * The rule in force for this account, or null when none is configured.
     */
    public function for(
        BusinessAccount $account,
        ?CarbonImmutable $at = null,
        ?int $websiteId = null,
        ?int $domainId = null,
        ?int $hostingId = null,
    ): ?DepositRule {
        $at ??= CarbonImmutable::now();

        /** @var DepositRule|null $rule */
        $rule = $this->resolver->resolve(
            DepositRule::query()->effectiveAt($at)->get(),
            $this->context($account, $websiteId, $domainId, $hostingId),
            $at,
        );

        return $rule;
    }

    /**
     * Every rule that applies, best first — for showing an administrator what
     * won and what it beat.
     *
     * @return array<int, DepositRule>
     */
    public function applicable(
        BusinessAccount $account,
        ?CarbonImmutable $at = null,
        ?int $websiteId = null,
        ?int $domainId = null,
        ?int $hostingId = null,
    ): array {
        $at ??= CarbonImmutable::now();

        /** @var array<int, DepositRule> $rules */
        $rules = $this->resolver->applicable(
            DepositRule::query()->effectiveAt($at)->get(),
            $this->context($account, $websiteId, $domainId, $hostingId),
            $at,
        );

        return $rules;
    }

    /**
     * The facts the decision is made against.
     *
     * The **account** is the user scope, not the person: everything commercial
     * hangs off the business (D1, D23), and a deposit agreed with a business
     * does not follow one of its staff out of the door.
     */
    protected function context(
        BusinessAccount $account,
        ?int $websiteId,
        ?int $domainId,
        ?int $hostingId,
    ): RuleContext {
        return RuleContext::make()
            ->forUser($account->id)
            ->forPackage($this->packageIdFor($account))
            ->forWebsite($websiteId)
            ->for(RuleScope::Domain, $domainId)
            ->for(RuleScope::Hosting, $hostingId);
    }

    /**
     * The package this account is actually on.
     *
     * The account's own current subscription, which is the one thing that
     * already answers this question — deriving it a second way here is how two
     * screens end up disagreeing about which plan somebody is on.
     */
    protected function packageIdFor(BusinessAccount $account): ?int
    {
        return $account->currentPackage()->value('package_id');
    }
}
