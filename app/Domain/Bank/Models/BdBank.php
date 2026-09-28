<?php

namespace App\Domain\Bank\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payment-system institution from the bundled bank directory (see
 * database/data/bangladesh-bank/NOTICE.md). `bank_code` is fixed once written
 * (`bd_banks_locked_columns`); `payable` is nullable because the source
 * itself could not identify some institutions (e.g. code 050) — null means
 * "unknown", never "no".
 *
 * @property int $id
 * @property string $bank_code
 * @property string $name
 * @property string $slug
 * @property array<int, string>|null $aliases
 * @property bool|null $payable
 * @property bool $available_in_selector
 * @property int $branch_count
 * @property bool $is_active
 */
class BdBank extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'payable' => 'boolean',
            'available_in_selector' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<BdBankBranch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(BdBankBranch::class, 'bank_id');
    }

    /**
     * Selectable in a payout-method form: identified, payable, and not
     * deactivated by a later import.
     */
    public function isSelectable(): bool
    {
        return $this->is_active && $this->payable === true && $this->available_in_selector;
    }
}
