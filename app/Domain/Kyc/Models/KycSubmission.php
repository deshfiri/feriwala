<?php

namespace App\Domain\Kyc\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Domain\Kyc\Enums\KycStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One round of KYC submission (§7.3).
 *
 * @property KycStatus $status
 * @property int $round
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $deadline_at
 */
class KycSubmission extends Model
{
    use HasPublicId, HasStateMachine;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => KycStatus::class,
            'round' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'deadline_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<KycDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(KycDocument::class, 'kyc_submission_id');
    }

    /**
     * @return HasMany<KycSubmissionField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(KycSubmissionField::class, 'kyc_submission_id');
    }

    /**
     * @return HasMany<KycReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(KycReview::class, 'kyc_submission_id')->latest('created_at');
    }

    /**
     * Whether the §7.4 deadline has passed without the round being completed.
     */
    public function isOverdue(): bool
    {
        return $this->deadline_at !== null
            && $this->status->awaitsReview() === false
            && $this->status !== KycStatus::Approved
            && $this->deadline_at->isPast();
    }
}
