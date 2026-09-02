<?php

namespace App\Domain\Kyc\Models;

use App\Domain\Kyc\Enums\KycStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reviewer decision on a KYC submission (§7.3).
 *
 * Append-only. §7.3 requires every KYC action store its reviewer, previous
 * status, new status, timestamp, reason, internal note, and user-visible
 * feedback — a record that can be edited afterwards proves none of it.
 *
 * @property KycStatus|null $from_status
 * @property KycStatus $to_status
 */
class KycReview extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'from_status' => KycStatus::class,
            'to_status' => KycStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException(
            'KYC review history is append-only (requirements.txt §7.3).'
        ));

        static::deleting(fn () => throw new \RuntimeException(
            'KYC review history is append-only and cannot be deleted.'
        ));
    }

    /**
     * @return BelongsTo<KycSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class, 'kyc_submission_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
