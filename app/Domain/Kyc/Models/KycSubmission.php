<?php

namespace App\Domain\Kyc\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Kyc\Enums\KycStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One round of KYC submission (§7.3).
 *
 * Belongs to the business account rather than the person (D23): commercial
 * onboarding is completed once, by the owner, and an invited staff member has
 * no KYC of their own.
 *
 * @property int $business_account_id
 * @property KycStatus $status
 * @property int $round
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $deadline_at
 * @property CarbonImmutable|null $requested_at
 * @property int|null $requested_by
 * @property string|null $request_reason
 * @property string|null $request_instructions
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
            'requested_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
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
     * What this round was opened against (§7.2).
     *
     * A snapshot, not a live lookup. Later configuration changes must not
     * rewrite what an in-progress or historical round was asked for.
     *
     * @return HasMany<KycSubmissionRequirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(KycSubmissionRequirement::class, 'kyc_submission_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return HasMany<KycReview, $this>
     */
    public function reviews(): HasMany
    {
        // `id` breaks the tie — see the note on User::statusHistory(). Two
        // reviews can share a second, and their order is the story.
        return $this->hasMany(KycReview::class, 'kyc_submission_id')
            ->latest('created_at')
            ->latest('id');
    }

    /**
     * What the deadline has already caused for this round (§7.4).
     *
     * @return HasMany<KycDeadlineEvent, $this>
     */
    public function deadlineEvents(): HasMany
    {
        return $this->hasMany(KycDeadlineEvent::class, 'kyc_submission_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Whether an administrator asked for this round (§7.2).
     *
     * Distinct from a round the applicant opened themselves. The two mean
     * different things to everyone who reads them: one is somebody working
     * through onboarding, the other is a request the business has to answer.
     */
    public function wasRequested(): bool
    {
        return $this->requested_at !== null;
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
