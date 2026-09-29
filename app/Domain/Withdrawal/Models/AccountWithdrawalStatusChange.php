<?php

namespace App\Domain\Withdrawal\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\Withdrawal\Enums\AccountWithdrawalChangeSource;
use App\Domain\Withdrawal\Enums\AccountWithdrawalStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded move in a Client/Partner withdrawal's lifecycle.
 *
 * @property int $id
 * @property int $account_withdrawal_id
 * @property AccountWithdrawalStatus|null $previous_status
 * @property AccountWithdrawalStatus $new_status
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property AccountWithdrawalChangeSource $source
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 */
class AccountWithdrawalStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'account_withdrawal_status_history';

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => AccountWithdrawalStatus::class,
            'new_status' => AccountWithdrawalStatus::class,
            'source' => AccountWithdrawalChangeSource::class,
            'changed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AccountWithdrawal, $this>
     */
    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(AccountWithdrawal::class, 'account_withdrawal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
