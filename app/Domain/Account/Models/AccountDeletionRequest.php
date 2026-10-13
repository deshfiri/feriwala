<?php

namespace App\Domain\Account\Models;

use App\Concerns\HasPublicId;
use App\Domain\Account\Enums\DeletionRequestStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody asking to have their account deleted.
 *
 * @property int|null $user_id
 * @property int|null $supplier_id
 * @property DeletionRequestStatus $status
 * @property string $reason
 * @property CarbonImmutable $requested_at
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property-read User|null $user
 * @property-read Supplier|null $supplier
 */
class AccountDeletionRequest extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'pending'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeletionRequestStatus::class,
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === DeletionRequestStatus::Pending;
    }

    /**
     * The person or Supplier asking.
     */
    public function subject(): User|Supplier|null
    {
        return $this->user_id !== null ? $this->user : $this->supplier;
    }

    public function isForSupplier(): bool
    {
        return $this->supplier_id !== null;
    }
}
