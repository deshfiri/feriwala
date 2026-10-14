<?php

namespace App\Domain\Kyc\Models;

use App\Concerns\HasPublicId;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reviewer's verdict on one slot of one requirement of a Partner round (D30).
 *
 * Append-only: a verdict is never changed, a later one supersedes it. The note is
 * the instruction the applicant is shown — written by a person, never copied
 * from the evidence.
 *
 * @property string $requirement_key
 * @property string|null $slot_key
 * @property string|null $evidence_ref
 * @property string $status
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
class KycEvidenceReview extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
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
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
