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

    /**
     * The bank directory's current cache-bust version — the latest
     * completed `import` run, or 0 before anything has ever been imported.
     */
    public static function currentVersion(): int
    {
        return static::query()->where('mode', 'import')->max('id') ?? 0;
    }
}
