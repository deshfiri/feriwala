<?php

namespace App\Domain\Kyc\Models;

use App\Domain\Account\Models\BusinessAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * One thing that happened to a KYC round because of its deadline (§7.4).
 *
 * The unique index on `(kyc_submission_id, event)` is the idempotency
 * guarantee, and {@see claim()} is how it is used: the insert *is* the claim, so
 * two workers racing on the same overdue round resolve at the database rather
 * than on which of them read the row first. Only the winner notifies.
 *
 * Append-only, like the audit log. These rows are the record of whether someone
 * was warned before losing access, which is exactly the kind of thing a dispute
 * turns on.
 *
 * @property string $event
 * @property bool $account_restricted
 * @property CarbonImmutable|null $deadline_at
 * @property CarbonImmutable $created_at
 */
class KycDeadlineEvent extends Model
{
    public const WARNED = 'warned';

    public const ENFORCED = 'enforced';

    public const RESTORED = 'restored';

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_restricted' => 'boolean',
            'deadline_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException(
            'KYC deadline events are append-only.'
        ));

        static::deleting(fn () => throw new RuntimeException(
            'KYC deadline events are append-only.'
        ));
    }

    /**
     * Claim the right to act on this event, or find it already taken.
     *
     * @param  array<string, mixed>  $attributes
     * @return self|null the claim, or null when someone else holds it
     */
    public static function claim(
        KycSubmission $submission,
        string $event,
        array $attributes = [],
    ): ?self {
        try {
            return static::create([
                'kyc_submission_id' => $submission->id,
                'business_account_id' => $submission->business_account_id,
                'event' => $event,
                ...$attributes,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Someone got here first. Returning null rather than throwing: a
            // second attempt at an already-handled event is the expected
            // outcome of a retry, not a fault.
            return null;
        }
    }

    /**
     * @return BelongsTo<KycSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class, 'kyc_submission_id');
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }
}
