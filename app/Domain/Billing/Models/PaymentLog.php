<?php

namespace App\Domain\Billing\Models;

use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One exchange with a payment gateway (§42).
 *
 * Append-only, like the audit log and the ledger: a log somebody can edit after
 * the fact is not evidence of anything. Corrections are new entries.
 *
 * `context` has already been through {@see PaymentLogRedactor} before it reaches
 * here. Nothing writes a raw gateway payload into this table.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $payment_id
 * @property int|null $business_account_id
 * @property string $gateway
 * @property string $direction
 * @property string $event
 * @property string|null $reference
 * @property string|null $gateway_reference
 * @property int|null $amount_minor
 * @property string|null $currency_code
 * @property string|null $outcome
 * @property int|null $http_status
 * @property array<array-key, mixed>|null $context
 * @property string|null $ip_address
 * @property CarbonImmutable $created_at
 * @property-read Payment|null $payment
 */
class PaymentLog extends Model
{
    use HasPublicId;

    /** A gateway request we made. */
    public const OUTBOUND = 'outbound';

    /** Something a gateway sent us. */
    public const INBOUND = 'inbound';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('A payment log entry cannot be changed.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('A payment log entry cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'amount_minor' => 'integer',
            'http_status' => 'integer',
            'created_at' => 'immutable_datetime',
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
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }
}
