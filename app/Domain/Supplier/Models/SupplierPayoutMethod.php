<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\HasPublicId;
use App\Domain\Supplier\Enums\SupplierPayoutMethodStatus;
use App\Domain\Supplier\Enums\SupplierPayoutMethodType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a Supplier's withdrawals are paid (D25, P13-24).
 *
 * `details` is encrypted at rest — the same `encrypted:array` cast
 * `Supplier::$payout_details` already uses — and `#[Hidden]` below keeps it,
 * along with the raw details, out of every array/JSON form this model takes;
 * only {@see maskedNumber()} is ever meant to reach a screen. `last_four` is
 * stored in the clear on purpose, so a masked display never has to decrypt
 * anything.
 *
 * A withdrawal never reads this row for its own payout details — it
 * snapshots them once, at request time, onto itself
 * ({@see SupplierWithdrawal::$payout_snapshot}), so editing or archiving a
 * method afterwards can never change what an existing withdrawal says it was
 * paid to.
 *
 * @property int $id
 * @property string $public_id
 * @property int $supplier_id
 * @property SupplierPayoutMethodType $type
 * @property string $label
 * @property array<string, mixed>|null $details
 * @property string $last_four
 * @property bool $is_default
 * @property SupplierPayoutMethodStatus $status
 * @property CarbonImmutable|null $verified_at
 * @property int|null $verified_by
 * @property CarbonImmutable $created_at
 * @property-read Supplier $supplier
 */
class SupplierPayoutMethod extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['details'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SupplierPayoutMethodType::class,
            'status' => SupplierPayoutMethodStatus::class,
            'details' => 'encrypted:array',
            'is_default' => 'boolean',
            'verified_at' => 'immutable_datetime',
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
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isActive(): bool
    {
        return $this->status === SupplierPayoutMethodStatus::Active;
    }

    public function maskedNumber(): string
    {
        return '••••'.$this->last_four;
    }

    /**
     * What a withdrawal snapshots onto itself at request time — everything a
     * later screen or staff review needs, never the raw encrypted details.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'payout_method_id' => $this->public_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'label' => $this->label,
            'masked_number' => $this->maskedNumber(),
        ];
    }
}
