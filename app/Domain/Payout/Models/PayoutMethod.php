<?php

namespace App\Domain\Payout\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Domain\Bank\Models\BdBank;
use App\Domain\Bank\Models\BdBankBranch;
use App\Domain\Payout\Data\PayoutMethodSnapshot;
use App\Domain\Payout\Enums\PayoutMethodStatus;
use App\Domain\Payout\Enums\PayoutMethodType;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Supplier\Models\SupplierWithdrawal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a Supplier or a Client/Partner `BusinessAccount` is paid out (D25,
 * P13-24) — one shared, owner-polymorphic table, generalized from the
 * Supplier-only `SupplierPayoutMethod` (see the `payout_methods` migration's
 * own docblock for why).
 *
 * `details` is encrypted at rest (Laravel's own `encrypted:array` cast) and
 * `#[Hidden]`-equivalent via `$hidden` below, so the raw account number never
 * reaches an array/JSON form of this model; only {@see maskedNumber()} is
 * ever meant to reach a screen. `last_four` is stored in the clear on
 * purpose, so a masked display never has to decrypt anything, and
 * `fingerprint` is a keyed, non-reversible hash used only to detect the same
 * account registered twice for the same owner — never to recover the number.
 *
 * A withdrawal never reads this row for its own payout details — it
 * snapshots them once, at request time, onto itself
 * ({@see SupplierWithdrawal::$payout_snapshot}),
 * so editing or archiving a method afterwards can never change what an
 * existing withdrawal says it was paid to.
 *
 * @property int $id
 * @property string $public_id
 * @property string $owner_type
 * @property int $owner_id
 * @property PayoutMethodType $type
 * @property string $label
 * @property int|null $bd_bank_id
 * @property int|null $bd_bank_branch_id
 * @property array<string, mixed>|null $details
 * @property string $last_four
 * @property string $fingerprint
 * @property bool $is_default
 * @property PayoutMethodStatus $status
 * @property CarbonImmutable|null $verified_at
 * @property int|null $verified_by
 * @property CarbonImmutable $created_at
 * @property-read BdBank|null $bank
 * @property-read BdBankBranch|null $branch
 */
class PayoutMethod extends Model
{
    use HasPublicId, HasStateMachine;

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
            'type' => PayoutMethodType::class,
            'status' => PayoutMethodStatus::class,
            'details' => 'encrypted:array',
            'is_default' => 'boolean',
            'verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BdBank, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(BdBank::class, 'bd_bank_id');
    }

    /**
     * @return BelongsTo<BdBankBranch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(BdBankBranch::class, 'bd_bank_branch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function ownedBy(PayoutOwnerType $type, int $ownerId): bool
    {
        return $this->owner_type === $type->value && $this->owner_id === $ownerId;
    }

    public function isActive(): bool
    {
        return $this->status === PayoutMethodStatus::Active;
    }

    public function maskedNumber(): string
    {
        return '••••'.$this->last_four;
    }

    /**
     * What a withdrawal snapshots onto itself at request time — everything a
     * later screen or staff review needs, never the raw encrypted details.
     */
    public function toSnapshot(): PayoutMethodSnapshot
    {
        return new PayoutMethodSnapshot(
            payoutMethodId: $this->public_id,
            type: $this->type->value,
            typeLabel: $this->type->label(),
            label: $this->label,
            maskedNumber: $this->maskedNumber(),
            bankName: $this->bank?->name,
            branchName: $this->branch?->name,
        );
    }
}
