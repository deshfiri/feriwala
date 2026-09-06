<?php

namespace App\Domain\Billing\Models;

use App\Casts\MoneyCast;
use App\Domain\Tax\Enums\TaxMode;
use App\Domain\Tax\Models\TaxRate;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The tax charged on one payment at one rate (D19).
 *
 * A **copy**, not a reference. The code, the label and the basis points are
 * written in at the moment of the charge, so a rate change next year cannot
 * rewrite what an issued invoice says — and an invoice whose arithmetic shifts
 * under it is worse than one showing a superseded rate.
 *
 * The taxable base is stored beside the tax because a return needs both, and
 * dividing the tax back out by the rate is arithmetic that goes wrong on the
 * rounding.
 *
 * @property string $tax_code
 * @property string $label
 * @property int $rate_basis_points
 * @property TaxMode $mode
 * @property Money $taxable_amount_minor
 * @property Money $tax_amount_minor
 * @property string $currency_code
 * @property-read Payment $payment
 */
class PaymentTaxLine extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => TaxMode::class,
            'rate_basis_points' => 'integer',
            'taxable_amount_minor' => MoneyCast::class,
            'tax_amount_minor' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * "15%", as it was on the day.
     */
    public function formattedRate(): string
    {
        $percent = number_format(
            $this->rate_basis_points / TaxRate::BASIS_POINTS_PER_PERCENT,
            2,
            '.',
            '',
        );

        return rtrim(rtrim($percent, '0'), '.').'%';
    }
}
