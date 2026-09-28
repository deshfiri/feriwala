<?php

namespace App\Domain\Bank\Models;

use App\Domain\Bank\Actions\ImportBdBanks;
use Illuminate\Database\Eloquent\Model;

/**
 * One append-only provenance row per {@see ImportBdBanks}
 * run — never updated or deleted (`bd_bank_imports_no_update`/`_no_delete`).
 */
class BdBankImport extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imported_at' => 'immutable_datetime',
        ];
    }
}
