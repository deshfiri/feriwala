<?php

use App\Support\Rules\RuleContext;
use App\Support\Rules\RuleResolver;
use App\Support\Rules\RuleScope;
use App\Support\Rules\ScopedRule;

class StubRule implements ScopedRule
{
    public function __construct(
        public string $name,
        public RuleScope $scope,
        public int|string|null $scopeId = null,
        public int $priority = 0,
        public ?DateTimeInterface $from = null,
        public ?DateTimeInterface $until = null,
        public bool $active = true,
        public int $id = 1,
    ) {}

    public function ruleScope(): RuleScope
    {
        return $this->scope;
    }

    public function ruleScopeId(): int|string|null
    {
        return $this->scopeId;
    }

    public function rulePriority(): int
    {
        return $this->priority;
    }

    public function ruleEffectiveFrom(): ?DateTimeInterface
    {
        return $this->from;
    }

    public function ruleEffectiveUntil(): ?DateTimeInterface
    {
        return $this->until;
    }

    public function ruleIsActive(): bool
    {
        return $this->active;
    }

    public function ruleIdentifier(): int|string
    {
        return $this->id;
    }
}

function resolver(): RuleResolver
{
    return new RuleResolver;
}

function at(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date);
}

describe('scope precedence', function () {
    it('prefers a user rule over a package rule over a global default', function () {
        $rules = [
            new StubRule('global', RuleScope::Global, id: 1),
            new StubRule('package', RuleScope::Package, scopeId: 7, id: 2),
            new StubRule('user', RuleScope::User, scopeId: 42, id: 3),
        ];

        $context = RuleContext::make()->forUser(42)->forPackage(7);

        expect(resolver()->resolve($rules, $context)->name)->toBe('user');
    });

    it('honours the §27.3 rule that a user-specific rule overrides the default', function () {
        $rules = [
            new StubRule('default', RuleScope::Global, priority: 999, id: 1),
            new StubRule('for-this-user', RuleScope::User, scopeId: 42, priority: 0, id: 2),
        ];

        // Even with a far higher priority, the global default loses to the
        // user-specific rule — specificity is decided before priority.
        expect(resolver()->resolve($rules, RuleContext::make()->forUser(42))->name)
            ->toBe('for-this-user');
    });

    it('lets a campaign override standing rules', function () {
        $rules = [
            new StubRule('user', RuleScope::User, scopeId: 42, id: 1),
            new StubRule('eid-campaign', RuleScope::Campaign, scopeId: 5, id: 2),
        ];

        $context = RuleContext::make()->forUser(42)->forCampaign(5);

        expect(resolver()->resolve($rules, $context)->name)->toBe('eid-campaign');
    });

    it('ignores a rule scoped to a different record', function () {
        $rules = [
            new StubRule('global', RuleScope::Global, id: 1),
            new StubRule('someone-else', RuleScope::User, scopeId: 99, id: 2),
        ];

        expect(resolver()->resolve($rules, RuleContext::make()->forUser(42))->name)
            ->toBe('global');
    });

    it('ignores a scoped rule when the context carries no such scope', function () {
        $rules = [new StubRule('package', RuleScope::Package, scopeId: 7, id: 1)];

        expect(resolver()->resolve($rules, RuleContext::make()->forUser(42)))->toBeNull();
    });

    it('always considers a global rule applicable', function () {
        $rules = [new StubRule('global', RuleScope::Global, id: 1)];

        expect(resolver()->resolve($rules, RuleContext::make())->name)->toBe('global');
    });
});

describe('effective window', function () {
    it('excludes a rule that has not started', function () {
        $rules = [new StubRule('future', RuleScope::Global, from: at('2026-10-01'), id: 1)];

        expect(resolver()->resolve($rules, RuleContext::make(), at('2026-09-01')))->toBeNull();
    });

    it('excludes a rule that has expired', function () {
        $rules = [new StubRule('past', RuleScope::Global, until: at('2026-08-01'), id: 1)];

        expect(resolver()->resolve($rules, RuleContext::make(), at('2026-09-01')))->toBeNull();
    });

    it('includes a rule starting exactly now', function () {
        $rules = [new StubRule('starts-now', RuleScope::Global, from: at('2026-09-01'), id: 1)];

        expect(resolver()->resolve($rules, RuleContext::make(), at('2026-09-01'))->name)
            ->toBe('starts-now');
    });

    it('hands over cleanly at the boundary with no gap and no overlap', function () {
        $rules = [
            new StubRule('old', RuleScope::Global, until: at('2026-09-01 00:00:00'), id: 1),
            new StubRule('new', RuleScope::Global, from: at('2026-09-01 00:00:00'), id: 2),
        ];

        $applicableAtBoundary = resolver()->applicable($rules, RuleContext::make(), at('2026-09-01 00:00:00'));

        expect($applicableAtBoundary)->toHaveCount(1)
            ->and($applicableAtBoundary[0]->name)->toBe('new');
    });

    it('excludes inactive rules', function () {
        $rules = [
            new StubRule('disabled', RuleScope::User, scopeId: 42, active: false, id: 1),
            new StubRule('global', RuleScope::Global, id: 2),
        ];

        expect(resolver()->resolve($rules, RuleContext::make()->forUser(42))->name)->toBe('global');
    });
});

describe('tie-breaking', function () {
    it('uses priority within the same scope', function () {
        $rules = [
            new StubRule('low', RuleScope::Global, priority: 1, id: 1),
            new StubRule('high', RuleScope::Global, priority: 10, id: 2),
        ];

        expect(resolver()->resolve($rules, RuleContext::make())->name)->toBe('high');
    });

    it('prefers the more recently effective rule when priority ties', function () {
        $rules = [
            new StubRule('older', RuleScope::Global, from: at('2026-01-01'), id: 1),
            new StubRule('newer', RuleScope::Global, from: at('2026-08-01'), id: 2),
        ];

        expect(resolver()->resolve($rules, RuleContext::make(), at('2026-09-01'))->name)
            ->toBe('newer');
    });

    it('prefers a deliberately dated rule over one that has always applied', function () {
        $rules = [
            new StubRule('always', RuleScope::Global, from: null, id: 1),
            new StubRule('dated', RuleScope::Global, from: at('2026-08-01'), id: 2),
        ];

        expect(resolver()->resolve($rules, RuleContext::make(), at('2026-09-01'))->name)
            ->toBe('dated');
    });

    it('is deterministic when everything else ties', function () {
        $rules = [
            new StubRule('a', RuleScope::Global, id: 1),
            new StubRule('b', RuleScope::Global, id: 2),
        ];

        // Same answer regardless of the order they arrive in, so two servers
        // cannot calculate the same commission differently.
        expect(resolver()->resolve($rules, RuleContext::make())->name)->toBe('b')
            ->and(resolver()->resolve(array_reverse($rules), RuleContext::make())->name)->toBe('b');
    });
});

describe('explanation', function () {
    it('returns every applicable rule best first, so an admin can see what won and what it beat', function () {
        $rules = [
            new StubRule('global', RuleScope::Global, id: 1),
            new StubRule('package', RuleScope::Package, scopeId: 7, id: 2),
            new StubRule('user', RuleScope::User, scopeId: 42, id: 3),
            new StubRule('other-user', RuleScope::User, scopeId: 99, id: 4),
        ];

        $context = RuleContext::make()->forUser(42)->forPackage(7);

        expect(array_map(fn (StubRule $r) => $r->name, resolver()->applicable($rules, $context)))
            ->toBe(['user', 'package', 'global']);
    });

    it('returns nothing when no rule applies', function () {
        expect(resolver()->applicable([], RuleContext::make()))->toBe([]);
    });
});
