<?php

namespace App\Domain\Billing\Models;

use App\Casts\MoneyCast;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One line of an issued invoice (§8.2, §9).
 *
 * Copied from the payment's allocation at issue rather than joined at read
 * time, and immutable afterwards. The label is copied too: a fee renamed next
 * year must not retitle a line on an invoice somebody already has in their
 * filing.
 *
 * @property string $type
 * @property string $label
 * @property Money $amount_minor
 * @property bool $is_deduction
 */
class InvoiceLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => MoneyCast::class,
            'is_deduction' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException(
            'An invoice line is immutable.'
        ));

        static::deleting(fn () => throw new RuntimeException(
            'An invoice line cannot be deleted.'
        ));
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The amount as it affects the total — negative for a deduction.
     *
     * The stored figure stays positive; only this flips it, so a report summing
     * discounts never has to guess at the sign. Same rule as a quote line.
     */
    public function signedAmount(): Money
    {
        return $this->is_deduction
            ? $this->amount_minor->negated()
            : $this->amount_minor;
    }
}
