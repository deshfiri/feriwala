<?php

namespace App\Domain\Tax\Models;

use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\TaxExemptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account that pays no tax, and the authority for it (D19).
 *
 * Not a boolean on `business_accounts`. An exemption has a reason, an expiry,
 * and someone who granted it — and when a tax inspector asks why no VAT was
 * charged on an invoice from March, "the flag was on" is not an answer. Keeping
 * the grant as a dated row means the invoice can be defended years later, and
 * an expired certificate stops being honoured on its own.
 *
 * Revoked rather than deleted, for the same reason.
 *
 * @property string $public_id
 * @property int $business_account_id
 * @property string $reason
 * @property string|null $certificate_reference
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property CarbonImmutable|null $revoked_at
 * @property-read BusinessAccount $businessAccount
 */
class TaxExemption extends Model
{
    /** @use HasFactory<TaxExemptionFactory> */
    use HasFactory, HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): TaxExemptionFactory
    {
        return TaxExemptionFactory::new();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeEffectiveAt(Builder $query, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();

        $query->whereNull('revoked_at')
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>', $at));
    }

    public function appliesAt(CarbonImmutable $at): bool
    {
        return $this->revoked_at === null
            && $this->effective_from->lessThanOrEqualTo($at)
            && ($this->effective_until === null || $this->effective_until->greaterThan($at));
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
