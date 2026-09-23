<?php

namespace App\Domain\Billing\Models;

use App\Casts\MoneyCast;
use App\Domain\Billing\Enums\AllocationType;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of a payment (§5.1, §9).
 *
 * @property AllocationType $type
 * @property Money $amount
 */
class PaymentAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => AllocationType::class,
            'amount' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function label(): string
    {
        return $this->description ?? $this->type->label();
    }
}
