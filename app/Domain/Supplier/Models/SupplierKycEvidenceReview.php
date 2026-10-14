<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\HasPublicId;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reviewer's verdict on one slot of one requirement of a Supplier round (D30).
 * The Supplier twin of `App\Domain\Kyc\Models\KycEvidenceReview` — separate table,
 * same rules (D25).
 *
 * @property string $requirement_key
 * @property string|null $slot_key
 * @property string $status
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
class SupplierKycEvidenceReview extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<SupplierKycSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(SupplierKycSubmission::class, 'supplier_kyc_submission_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
