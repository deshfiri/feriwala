<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Wallet\Actions\ManageDepositRules;
use App\Domain\Wallet\Models\DepositRule;
use App\Domain\Wallet\Models\DepositRuleChange;
use App\Models\User;
use App\Support\Rules\RuleScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The history of a deposit requirement (P2-12, §24.1).
 *
 * A deposit rule is the reason an account is restricted or allowed to trade, so
 * "the minimum balance was two thousand until the 3rd, then somebody raised it"
 * has to be answerable months later from one place.
 */

beforeEach(function () {
    $this->actor = User::factory()->staff()->create();
});

function manageRules(): ManageDepositRules
{
    return app(ManageDepositRules::class);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createDepositRule(array $attributes = [], ?CarbonImmutable $from = null): DepositRule
{
    return manageRules()->create(
        actor: test()->actor,
        scope: RuleScope::Global,
        scopeId: null,
        attributes: ['minimum_balance_minor' => 200000, ...$attributes],
        effectiveFrom: $from ?? CarbonImmutable::now()->subMonth(),
        reason: 'The standing policy.',
    );
}

describe('creating a rule', function () {
    it('records the figures it was created with', function () {
        $rule = createDepositRule(['required_initial_deposit_minor' => 500000]);

        $change = DepositRuleChange::query()->where('deposit_rule_id', $rule->id)->firstOrFail();

        expect($change->action)->toBe(DepositRuleChange::CREATED)
            // A creation has no previous state, which is not the same as one
            // whose previous state was empty.
            ->and($change->before)->toBeNull()
            ->and($change->after['minimum_balance_minor'])->toBe(200000)
            ->and($change->after['required_initial_deposit_minor'])->toBe(500000)
            ->and($change->actor_id)->toBe($this->actor->id)
            ->and($change->reason)->toBe('The standing policy.');
    });

    it('records the date it takes effect, not the date it was made', function () {
        // §24.1 lets a deposit be decided today and begin next month.
        $starts = CarbonImmutable::now()->addMonth();

        $rule = createDepositRule(from: $starts);

        $change = DepositRuleChange::query()->where('deposit_rule_id', $rule->id)->firstOrFail();

        expect($change->effective_from->toDateString())->toBe($starts->toDateString())
            ->and($change->created_at->toDateString())->toBe(CarbonImmutable::now()->toDateString());
    });

    it('writes it to the audit log as a sensitive act', function () {
        // Somebody changed what a business is required to hold.
        $rule = createDepositRule();

        $entry = AuditLog::query()->where('action', 'wallet.deposit_rule_created')->firstOrFail();

        expect($entry->auditable_id)->toBe($rule->id)
            ->and($entry->actor_id)->toBe($this->actor->id)
            ->and($entry->is_sensitive)->toBeTrue();
    });

    it('refuses a window that overlaps one already open', function () {
        /*
         * Two rules in force for the same subject would make the requirement
         * depend on which row the resolver read first.
         */
        createDepositRule();

        expect(fn () => createDepositRule())
            ->toThrow(InvalidArgumentException::class);
    });

    it('allows a narrower rule to overlap a wider one', function () {
        // That is what "more specific" means.
        createDepositRule();

        $package = manageRules()->create(
            actor: $this->actor,
            scope: RuleScope::Package,
            scopeId: 42,
            attributes: ['minimum_balance_minor' => 0],
            effectiveFrom: CarbonImmutable::now()->subWeek(),
        );

        expect($package->exists)->toBeTrue();
    });

    it('refuses a scope without a subject, and a global rule with one', function () {
        expect(fn () => manageRules()->create(
            actor: $this->actor,
            scope: RuleScope::Package,
            scopeId: null,
            attributes: [],
            effectiveFrom: CarbonImmutable::now(),
        ))->toThrow(InvalidArgumentException::class);

        expect(fn () => manageRules()->create(
            actor: $this->actor,
            scope: RuleScope::Global,
            scopeId: 7,
            attributes: [],
            effectiveFrom: CarbonImmutable::now(),
        ))->toThrow(InvalidArgumentException::class);
    });
});

describe('changing a rule', function () {
    it('closes one and opens another rather than editing a figure', function () {
        /*
         * The whole point. An obligation captured last month still resolves to
         * the rule it was captured from.
         */
        $original = createDepositRule(['minimum_balance_minor' => 200000]);

        $closesAt = CarbonImmutable::now();

        manageRules()->close($this->actor, $original, $closesAt, 'Raising the floor.');

        $replacement = createDepositRule(['minimum_balance_minor' => 500000], $closesAt);

        expect($original->fresh()->minimum_balance_minor->minorUnits)->toBe(200000)
            ->and($original->fresh()->effective_until)->not->toBeNull()
            ->and($replacement->minimum_balance_minor->minorUnits)->toBe(500000);
    });

    it('records what the figures were before the close', function () {
        $rule = createDepositRule(['minimum_balance_minor' => 200000]);

        manageRules()->close($this->actor, $rule, CarbonImmutable::now(), 'Raising the floor.');

        $change = DepositRuleChange::query()
            ->where('deposit_rule_id', $rule->id)
            ->where('action', DepositRuleChange::CLOSED)
            ->firstOrFail();

        expect($change->before['minimum_balance_minor'])->toBe(200000)
            ->and($change->before['effective_until'])->toBeNull()
            ->and($change->after['effective_until'])->not->toBeNull()
            ->and($change->reason)->toBe('Raising the floor.');
    });

    it('keeps the row when a rule is switched off', function () {
        // Closing says "this stopped applying on the 3rd"; deactivating says
        // "this should never have applied". Both keep the row.
        $rule = createDepositRule();

        manageRules()->deactivate($this->actor, $rule, 'Created in error.');

        expect(DepositRule::query()->find($rule->id))->not->toBeNull()
            ->and($rule->fresh()->is_active)->toBeFalse()
            ->and(DepositRuleChange::query()
                ->where('action', DepositRuleChange::DEACTIVATED)
                ->exists())->toBeTrue();
    });

    it('refuses to close a rule before it began', function () {
        $rule = createDepositRule(from: CarbonImmutable::now());

        expect(fn () => manageRules()->close(
            $this->actor,
            $rule,
            CarbonImmutable::now()->subWeek(),
        ))->toThrow(InvalidArgumentException::class);
    });
});

describe('immutability', function () {
    it('refuses to be edited through the model', function () {
        $change = DepositRuleChange::query()->where(
            'deposit_rule_id',
            createDepositRule()->id,
        )->firstOrFail();

        expect(fn () => $change->forceFill(['reason' => 'Something else'])->save())
            ->toThrow(RuntimeException::class);
    });

    it('refuses an update that goes around the model entirely', function () {
        $change = DepositRuleChange::query()->where(
            'deposit_rule_id',
            createDepositRule()->id,
        )->firstOrFail();

        expect(fn () => DB::transaction(fn () => DB::table('deposit_rule_changes')
            ->where('id', $change->id)
            ->update(['reason' => 'Something else'])))
            ->toThrow(QueryException::class);
    });
});
