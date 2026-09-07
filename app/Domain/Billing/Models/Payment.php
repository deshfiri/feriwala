<?php

namespace App\Domain\Billing\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Concerns\HasStateMachine;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Support\Money\Money;
use App\Support\References\ReferencePrefix;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One payment, with its components (§9, §26).
 *
 * @property int $id
 * @property int $business_account_id
 * @property PaymentStatus $status
 * @property PaymentPurpose $purpose
 * @property Money $amount_minor
 * @property Money $revenue_minor
 * @property CarbonImmutable|null $initiated_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property-read BusinessAccount|null $businessAccount
 */
class Payment extends Model
{
    use HasPublicId, HasReference, HasStateMachine;

    protected $guarded = [];

    /**
     * The gateway reference is not secret, but it is not for public payloads
     * either — it identifies the transaction at the provider.
     *
     * @var list<string>
     */
    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'purpose' => PaymentPurpose::class,
            'amount_minor' => MoneyCast::class,
            'revenue_minor' => MoneyCast::class,
            'initiated_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function referencePrefix(): ReferencePrefix
    {
        return ReferencePrefix::Payment;
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('sort_order');
    }

    /**
     * The tax charged, per rate (D19).
     *
     * Alongside the single rolled-up Tax allocation, not instead of it. An
     * invoice reads "VAT 15% on 5,000 — 750" per rate, and a tax return needs
     * the taxable base as well as the tax; neither survives a total.
     *
     * @return HasMany<PaymentTaxLine, $this>
     */
    public function taxLines(): HasMany
    {
        return $this->hasMany(PaymentTaxLine::class)->orderBy('id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The amount allocated to one component (§5.1).
     *
     * This is what makes "how much registration fee did we take in March"
     * answerable without unpicking totals.
     */
    public function allocatedTo(AllocationType $type): Money
    {
        $total = Money::of(0, $this->amount_minor->currency);

        foreach ($this->allocations as $allocation) {
            if ($allocation->type === $type) {
                $total = $total->plus($allocation->amount_minor);
            }
        }

        return $total;
    }

    /**
     * Whether the allocations still add up to the amount charged.
     *
     * Used by reconciliation (§28.1). A payment whose parts no longer sum to its
     * whole means something wrote to one and not the other, and that is worth
     * an alert rather than a silent correction.
     */
    public function allocationsBalance(): bool
    {
        $sum = Money::of(0, $this->amount_minor->currency);

        foreach ($this->allocations as $allocation) {
            $sum = $allocation->type->isDeduction()
                ? $sum->minus($allocation->amount_minor)
                : $sum->plus($allocation->amount_minor);
        }

        return $sum->equals($this->amount_minor);
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereIn('status', [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded]);
    }
}
