<?php

namespace App\Domain\Account\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staff confirmed a contact channel on someone's behalf (append-only).
 *
 * @property int $id
 * @property string $identity_type 'user' or 'supplier'
 * @property int $identity_id
 * @property string $channel 'email' or 'mobile'
 * @property int $verified_by
 * @property string $reason
 * @property CarbonImmutable $created_at
 */
class StaffContactVerification extends Model
{
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
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
