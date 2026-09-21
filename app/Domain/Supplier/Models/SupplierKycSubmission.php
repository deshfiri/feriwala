<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Domain\Supplier\Enums\SupplierKycStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One round of Supplier KYC (D25, P13-7). Belongs to the Supplier, never a
 * `BusinessAccount` — see `App\Domain\Supplier\Models\Supplier`'s docblock for
 * why this is not the generic Client/Partner KYC engine.
 *
 * @property int $id
 * @property string $public_id
 * @property int $supplier_id
 * @property int $round
 * @property SupplierKycStatus $status
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $reviewed_by
 * @property string|null $decision_note
 * @property-read Supplier $supplier
 * @property-read User|null $reviewedBy
 * @property-read Collection<int, SupplierKycDocument> $documents
 */
class SupplierKycSubmission extends Model
{
    use HasPublicId, HasStateMachine;

    protected $guarded = [];

    protected $attributes = [
        'status' => SupplierKycStatus::Draft->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupplierKycStatus::class,
            'round' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<SupplierKycDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(SupplierKycDocument::class, 'supplier_kyc_submission_id');
    }
}
