<?php

namespace App\Domain\Wallet\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Wallet\Enums\DepositFrequency;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Rules\RuleResolver;
use App\Support\Rules\RuleScope;
use App\Support\Rules\ScopedRule;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated deposit requirement, in one scope (§24.1).
 *
 * Resolved through the shared {@see RuleResolver}: most
 * specific scope wins, then explicit priority, then the most recently effective
 * rule, then the identifier so two servers cannot disagree. A rule is never
 * edited into a new figure — raising a minimum balance closes one row and opens
 * another, so what an account was required to hold in March is still answerable
 * in June.
 *
 * @property int $id
 * @property string $public_id
 * @property RuleScope $scope
 * @property int|null $scope_id
 * @property Money $required_initial_deposit_minor
 * @property Money $minimum_balance_minor
 * @property Money $required_top_up_minor
 * @property string $currency_code
 * @property int|null $deposit_deadline_days
 * @property int|null $grace_period_days
 * @property DepositFrequency $frequency
 * @property int|null $frequency_days
 * @property Money|null $low_balance_threshold_minor
 * @property Money|null $critical_balance_threshold_minor
 * @property bool $restricts_chargeable_services
 * @property bool $pauses_website_setup
 * @property bool $disables_website
 * @property bool $restricts_account
 * @property bool $disables_account
 * @property bool $restores_automatically
 * @property int $priority
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property bool $is_active
 * @property string|null $note
 * @property int|null $created_by
 */
class DepositRule extends Model implements ScopedRule
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * The column defaults, stated again in PHP.
     *
     * A model that has to be re-read from the database before its own switches
     * are legible is one a caller will eventually read too early — and here that
     * would mean asking "does this rule disable accounts?" and getting null,
     * which is neither yes nor no.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'required_initial_deposit_minor' => 0,
        'minimum_balance_minor' => 0,
        'required_top_up_minor' => 0,
        'currency_code' => 'BDT',
        'frequency' => 'one_time',
        'restricts_chargeable_services' => false,
        'pauses_website_setup' => false,
        'disables_website' => false,
        'restricts_account' => false,
        'disables_account' => false,
        'restores_automatically' => true,
        'priority' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => RuleScope::class,
            'frequency' => DepositFrequency::class,
            'required_initial_deposit_minor' => MoneyCast::class,
            'minimum_balance_minor' => MoneyCast::class,
            'required_top_up_minor' => MoneyCast::class,
            'low_balance_threshold_minor' => MoneyCast::class,
            'critical_balance_threshold_minor' => MoneyCast::class,
            'restricts_chargeable_services' => 'boolean',
            'pauses_website_setup' => 'boolean',
            'disables_website' => 'boolean',
            'restricts_account' => 'boolean',
            'disables_account' => 'boolean',
            'restores_automatically' => 'boolean',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Rules that could apply at a moment.
     *
     * The window is filtered in the database and the scope in the resolver: a
     * rule for another account is not a weaker match, it is simply irrelevant,
     * and the resolver is where that judgement already lives.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEffectiveAt(Builder $query, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();

        $query->where('is_active', true)
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>', $at));
    }

    public function ruleScope(): RuleScope
    {
        return $this->scope;
    }

    public function ruleScopeId(): int|string|null
    {
        return $this->scope_id;
    }

    public function rulePriority(): int
    {
        return $this->priority;
    }

    public function ruleEffectiveFrom(): ?DateTimeInterface
    {
        return $this->effective_from;
    }

    public function ruleEffectiveUntil(): ?DateTimeInterface
    {
        return $this->effective_until;
    }

    public function ruleIsActive(): bool
    {
        return $this->is_active;
    }

    public function ruleIdentifier(): int|string
    {
        return $this->id;
    }

    /**
     * Whether this rule asks for anything at all.
     *
     * A rule of all zeroes is legitimate — it is how an administrator says "this
     * package requires no deposit" and overrides a global one that does.
     */
    public function requiresAnything(): bool
    {
        return $this->required_initial_deposit_minor->isPositive()
            || $this->minimum_balance_minor->isPositive();
    }

    /**
     * The balance below which the account is in trouble (§24.1).
     *
     * The critical threshold where one is configured; otherwise the minimum
     * balance itself, because falling below what you are required to keep is
     * the condition §24.3 acts on whether or not somebody set a second figure.
     */
    public function criticalFloor(): Money
    {
        return $this->critical_balance_threshold_minor ?? $this->minimum_balance_minor;
    }

    /**
     * The balance that is worth a warning but not an action (§24.1).
     */
    public function lowFloor(): ?Money
    {
        return $this->low_balance_threshold_minor;
    }
}
