<?php

namespace App\Domain\Billing\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Package\Models\Package;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated price for one fee (§9).
 *
 * A rule is never edited into a new price. Raising a fee is a new row with its
 * own window and the old one closed, so "what was the registration fee in
 * March" has an answer, and a quote resolved against a date reproduces itself.
 *
 * @property int $id
 * @property string $public_id
 * @property FeeType $fee_type
 * @property int|null $package_id
 * @property Money $amount_minor
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property bool $is_active
 * @property string|null $note
 * @property-read Package|null $package
 * @property-read User|null $createdBy
 */
class FeeRule extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_type' => FeeType::class,
            'amount_minor' => MoneyCast::class,
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Rules in force at a moment.
     *
     * Inactive rules are excluded here rather than filtered by callers, so
     * nothing can accidentally price against a rule somebody switched off.
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

    /**
     * Whether this rule is in force right now.
     */
    public function isInForce(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        return $this->is_active
            && $this->effective_from->lte($at)
            && ($this->effective_until === null || $this->effective_until->gt($at));
    }
}
