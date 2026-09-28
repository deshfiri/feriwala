<?php

namespace App\Domain\Location\Models;

use App\Domain\Location\Actions\ImportBdLocations;
use Illuminate\Database\Eloquent\Model;

/**
 * One append-only row per importer run — see the migration's own docblock.
 * Written once by {@see ImportBdLocations} and never updated or deleted
 * (enforced in the database, not just here).
 *
 * @property int $id
 * @property string $source_url
 * @property string $commit_sha
 * @property string $checksum_en
 * @property string $checksum_bn
 * @property string $license
 * @property string $mode
 * @property int $divisions_count
 * @property int $districts_count
 * @property int $upazilas_count
 * @property int $unions_count
 * @property int $deactivated_count
 */
class BdLocationImport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'imported_at' => 'immutable_datetime',
        ];
    }

    /**
     * The location directory's current cache-bust version — the latest
     * completed `import` run, or 0 before anything has ever been imported.
     */
    public static function currentVersion(): int
    {
        return static::query()->where('mode', 'import')->max('id') ?? 0;
    }
}
