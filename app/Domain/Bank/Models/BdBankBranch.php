<?php

namespace App\Domain\Bank\Models;

use App\Domain\Location\Models\BdLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One branch, keyed by its own nine-digit BEFTN routing number (globally
 * unique — the first three digits are the parent bank's own code, enforced
 * by `feriwala_bd_bank_branch_routing_matches_bank`). `bank_id` and
 * `routing_number` are fixed once written; only contact/name/status fields
 * and `is_active` may change on a re-import.
 *
 * `district_location_id` resolves to the existing Bangladesh administrative
 * directory (see App\Domain\Bank\DistrictAliases); `district_source_name`
 * keeps the bank data's own district text even after that, so a branch
 * whose match later turns out to need a reviewed alias is still traceable
 * back to what the source actually said.
 *
 * @property int $id
 * @property int $bank_id
 * @property string $routing_number
 * @property string $name
 * @property string $slug
 * @property int|null $district_location_id
 * @property string $district_source_name
 * @property string|null $branch_code
 * @property string|null $original_branch_code
 * @property string|null $swift_code
 * @property string|null $address
 * @property string|null $telephone
 * @property string|null $email
 * @property string|null $fax
 * @property string $source
 * @property string $source_status
 * @property bool $is_active
 */
class BdBankBranch extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<BdBank, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(BdBank::class, 'bank_id');
    }

    /**
     * @return BelongsTo<BdLocation, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(BdLocation::class, 'district_location_id');
    }
}
