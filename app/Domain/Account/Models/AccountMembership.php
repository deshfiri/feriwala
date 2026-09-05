<?php

namespace App\Domain\Account\Models;

use App\Domain\Account\Enums\AccountRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A person's place inside one business account (D1).
 *
 * The owner has a membership too — they are a member with the Owner role, not a
 * special case beside the members table. That keeps "who may work in this
 * account" a single question with a single answer.
 *
 * A user appears at most **once** across the whole table, enforced by a unique
 * index on `user_id`. D1 puts staff under one account and rules out conversion
 * between account kinds, so a person belonging to two businesses has no meaning
 * here — and allowing it would resurrect the account switcher that P1-65 and
 * P1-68 exist to remove.
 *
 * @property int $id
 * @property int $business_account_id
 * @property int $user_id
 * @property AccountRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BusinessAccount $businessAccount
 * @property-read User $user
 */
#[Fillable(['business_account_id', 'user_id', 'role'])]
class AccountMembership extends Pivot
{
    protected $table = 'business_account_members';

    public $incrementing = true;

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AccountRole::class,
        ];
    }

    public function isOwner(): bool
    {
        return $this->role === AccountRole::Owner;
    }
}
