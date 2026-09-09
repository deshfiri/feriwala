<?php

namespace App\Domain\Tax\Models;

use App\Concerns\HasPublicId;
use App\Domain\Tax\Enums\TaxMode;
use App\Domain\Tax\Enums\TaxScope;
use Carbon\CarbonImmutable;
use Database\Factories\TaxRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Which rate applies to what, and from when (D19).
 *
 * Points at a rate by **code**, not by row. A rule bound to a specific rate row
 * would keep charging 15% after the rate changed — the precise failure that
 * versioning the rate exists to prevent.
 *
 * @property string $public_id
 * @property TaxScope $scope
 * @property string|null $scope_value
 * @property string $tax_code
 * @property TaxMode $mode
 * @property int $priority
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property bool $is_active
 * @property string|null $note
 */
class TaxRule extends Model
{
    /** @use HasFactory<TaxRuleFactory> */
    use HasFactory, HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => TaxScope::class,
            'mode' => TaxMode::class,
            'priority' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): TaxRuleFactory
    {
        return TaxRuleFactory::new();
    }

    /**
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

    /**
     * Active and inside its window right now.
     */
    public function isInForce(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        return $this->is_active
            && $this->effective_from->lessThanOrEqualTo($at)
            && ($this->effective_until === null || $this->effective_until->greaterThan($at));
    }

    /**
     * Whether this rule targets the thing described by `$scope`/`$value`.
     *
     * The comparison is case-insensitive on the value because a fee type, a
     * product public id and a category slug all arrive from different places
     * and only one of them is guaranteed to have been normalised.
     */
    public function matches(TaxScope $scope, ?string $value): bool
    {
        if ($this->scope !== $scope) {
            return false;
        }

        if (! $scope->requiresValue()) {
            return true;
        }

        return $value !== null
            && $this->scope_value !== null
            && mb_strtolower($this->scope_value) === mb_strtolower($value);
    }

    /**
     * How this rule sorts against another that also matched.
     *
     * Specificity first, priority only as a tie-break within it, then the newer
     * rule. Letting priority cross specificity would let one broad, high-priority
     * rule silently override every targeted rule beneath it.
     *
     * @return array{int, int, int}
     */
    public function precedence(): array
    {
        return [$this->scope->specificity(), $this->priority, $this->id];
    }
}
