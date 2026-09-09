<?php

namespace App\Domain\Billing\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * One issued invoice (§8.2, §9).
 *
 * **Immutable, like the audit log.** An invoice is a document somebody has a
 * copy of; changing it after the fact means the copy and the record disagree,
 * and the one that is wrong is always ours. A correction is a new document, not
 * an edit — the same rule §36.1 applies to ledger entries, for the same reason.
 *
 * Whether it has been **paid** is not stored here. It is the payment's business,
 * and duplicating it would create two answers that drift apart the first time a
 * settlement arrives late. What is stored is what was billed.
 *
 * @property string $public_id
 * @property string $number
 * @property PaymentPurpose $purpose
 * @property Money $subtotal_minor
 * @property Money $total_minor
 * @property CarbonImmutable $issued_at
 * @property-read Payment|null $payment
 */
class Invoice extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => PaymentPurpose::class,
            'subtotal_minor' => MoneyCast::class,
            'total_minor' => MoneyCast::class,
            'issued_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException(
            'An invoice is immutable. Issue a corrective document instead.'
        ));

        static::deleting(fn () => throw new RuntimeException(
            'An invoice cannot be deleted.'
        ));
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order');
    }

    /**
     * Whether the money has actually arrived.
     *
     * Read from the payment, every time. An invoice that carried its own paid
     * flag would be a second answer to a question the payment already answers,
     * and §8.2's "a pending invoice grants nothing" depends on there being only
     * one.
     */
    public function isPaid(): bool
    {
        return $this->payment?->status->isSettled() ?? false;
    }

    /**
     * The number an invoice is issued under.
     *
     * Year-scoped and padded from the row id: unique by construction, readable
     * over the phone, and sortable. Derived from the id rather than a counter
     * table, because a counter is a second thing to keep consistent and this one
     * cannot collide.
     */
    public static function numberFor(int $id, ?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now();

        return sprintf('INV-%s-%06d', $at->format('Y'), $id);
    }
}
