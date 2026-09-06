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
 * @property-read Payment $payment
 * @property-read BusinessAccount $businessAccount
 */
class RefundRequest extends Model
{
    /** @use HasFactory<RefundRequestFactory> */
    use HasFactory, HasPublicId, HasStateMachine;

    protected $guarded = [];

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
