<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Order\Enums\OrderRevisionSection;
use App\Domain\Order\Enums\OrderRevisionStatus;
use App\Domain\Order\Enums\RevisionCourierConsequence;
use App\Domain\Order\Enums\RevisionPaymentConsequence;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One applied correction of an order (D38).
 *
 * The only way an order's customer, addresses, lines, prices or delivery ever
 * change after placement: the database accepts a change to those columns only
 * together with the next applied revision, and only to exactly what that
 * revision's snapshots say. Append-only — written once, never edited, never
 * deleted.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $revision_number
 * @property OrderRevisionStatus $status
 * @property list<string> $sections
 * @property int $requested_by
 * @property int|null $applied_by
 * @property string $reason
 * @property string|null $internal_note
 * @property array<string, mixed> $before_snapshot
 * @property array<string, mixed> $requested_snapshot
 * @property array<string, mixed> $final_snapshot
 * @property string $currency_code
 * @property Money $total_before
 * @property Money $total_after
 * @property Money $total_difference signed: negative when the order now comes to less
 * @property list<array<string, mixed>> $stock_difference
 * @property RevisionPaymentConsequence $payment_consequence
 * @property RevisionCourierConsequence $courier_consequence
 * @property string $fingerprint
 * @property string $idempotency_key
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable $created_at
 * @property-read Order $order
 * @property-read User $requestedBy
 * @property-read User|null $appliedBy
 * @property-read Collection<int, OrderRevisionLine> $lines
 * @property-read Collection<int, OrderRevisionAdjustment> $adjustments
 */
class OrderRevision extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderRevisionStatus::class,
            'sections' => 'array',
            'before_snapshot' => 'array',
            'requested_snapshot' => 'array',
            'final_snapshot' => 'array',
            'total_before' => MoneyCast::class,
            'total_after' => MoneyCast::class,
            'total_difference' => MoneyCast::class,
            'stock_difference' => 'array',
            'payment_consequence' => RevisionPaymentConsequence::class,
            'courier_consequence' => RevisionCourierConsequence::class,
            'requested_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('An order revision is a record of a correction and cannot be changed.'));
        static::deleting(fn () => throw new LogicException('An order revision is a record of a correction and cannot be removed.'));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /**
     * @return HasMany<OrderRevisionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderRevisionLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderRevisionAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(OrderRevisionAdjustment::class)->orderBy('id');
    }

    /**
     * @return list<OrderRevisionSection>
     */
    public function changedSections(): array
    {
        return array_values(array_filter(array_map(
            fn (string $section) => OrderRevisionSection::tryFrom($section),
            $this->sections,
        )));
    }
}
