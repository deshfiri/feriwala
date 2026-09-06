<?php

namespace App\Domain\Tax\Models;

use App\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Database\Factories\TaxRateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One named rate, valid for one period (D19).
 *
 * A rate is **versioned, not edited**. When VAT moves from 15% to 12% the old
 * row is closed and a new one opened under the same code, because editing the
 * percentage in place would silently rewrite the arithmetic of every invoice
 * already issued against it — and an invoice that no longer adds up is worse
 * than one showing an old rate.
 *
 * Held in basis points: 1500 is 15.00%. Never a float, for the same reason money
 * is never a float (D4, §36.1).
 *
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property int $rate_basis_points
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property bool $is_active
 */
class TaxRate extends Model
{
    /** @use HasFactory<TaxRateFactory> */
    use HasFactory, HasPublicId;

    /** Basis points in one whole percent. */
    public const BASIS_POINTS_PER_PERCENT = 100;

    /** Basis points in 100%. */
    public const BASIS_POINTS_WHOLE = 10000;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_basis_points' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): TaxRateFactory
    {
        return TaxRateFactory::new();
    }

    /**
     * Active and inside its window at `$at`.
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

    public function appliesAt(CarbonImmutable $at): bool
    {
        return $this->is_active
            && $this->effective_from->lessThanOrEqualTo($at)
            && ($this->effective_until === null || $this->effective_until->greaterThan($at));
    }

    public function isZeroRated(): bool
    {
        return $this->rate_basis_points === 0;
    }

    /**
     * The rate as a percentage, for display only.
     *
     * Never use this in a calculation: it is a float, and the point of storing
     * basis points is that money arithmetic never touches one.
     */
    public function percent(): float
    {
        return $this->rate_basis_points / self::BASIS_POINTS_PER_PERCENT;
    }

    /**
     * "15%", or "7.5%" — trailing zeroes trimmed so a whole rate reads whole.
     */
    public function formattedPercent(): string
    {
        $formatted = number_format($this->percent(), 2, '.', '');

        return rtrim(rtrim($formatted, '0'), '.').'%';
    }

    public function label(): string
    {
        return sprintf('%s (%s)', $this->name, $this->formattedPercent());
    }
}
