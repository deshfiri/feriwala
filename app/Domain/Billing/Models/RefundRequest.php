<?php

namespace App\Domain\Billing\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\Refundability;
use App\Domain\Billing\Enums\RefundStatus;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\RefundRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One refund asked for, and what was decided (D17).
 *
 * Per component, not per payment. §5.1 makes the registration fee and the
 * package fee separately reportable, and a refund collapsing them could not be
 * reconciled against the payment it came from.
 *
 * The refundability rule in force at the time is **copied onto the row**. A
 * policy change later must not rewrite the basis on which a past decision was
 * taken — an administrator defending a March refusal needs the rule as it was
 * in March.
 *
 * @property string $public_id
 * @property AllocationType $allocation_type
 * @property Money $amount_minor
 * @property Refundability $refundability
 * @property RefundStatus $status
 * @property string $reason
 * @property string|null $decision_note
 * @property CarbonImmutable|null $decided_at
 * @property string|null $gateway
 * @property string|null $idempotency_key
 * @property string|null $gateway_refund_reference
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $processed_at
 * @property string|null $failure_reason
 * @property int|null $wallet_transaction_id
 * @property array<array-key, mixed>|null $evidence
 * @property-read Payment $payment
 * @property-read BusinessAccount $businessAccount
 */
class RefundRequest extends Model
{
    /** @use HasFactory<RefundRequestFactory> */
    use HasFactory, HasPublicId, HasStateMachine;

    protected $guarded = [];

    /**
     * The idempotency key is ours, and knowing it would let somebody collide
     * with a refund deliberately.
     *
     * @var list<string>
     */
    protected $hidden = ['idempotency_key'];

    protected static function booted(): void
    {
        /*
         * How much of a payment has been refunded is computed from these rows,
         * so a row somebody can remove is a way of making refunded money
         * disappear from the record. Refused here and by a database trigger.
         */
        static::deleting(function (): never {
            throw new RuntimeException('A refund request cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allocation_type' => AllocationType::class,
            'refundability' => Refundability::class,
            'status' => RefundStatus::class,
            'amount_minor' => MoneyCast::class,
            'decided_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'evidence' => 'array',
        ];
    }

    protected static function newFactory(): RefundRequestFactory
    {
        return RefundRequestFactory::new();
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
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return $this->status === RefundStatus::Requested;
    }
}
