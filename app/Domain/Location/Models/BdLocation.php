<?php

namespace App\Domain\Location\Models;

use App\Domain\Location\Actions\ImportBdLocations;
use App\Domain\Location\Enums\BdLocationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One node of the Bangladesh administrative directory (Division, District,
 * Upazila or Union).
 *
 * `source_id`/`source_parent_id`/`type`/`parent_id` are the node's identity —
 * fixed once written (`bd_locations_locked_columns`) and owned entirely by
 * {@see ImportBdLocations}. Nothing else may change them; a rename or a
 * deactivation touches `name_en`/`name_bn`/`is_active` only.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property BdLocationType $type
 * @property string $source_id
 * @property string|null $source_parent_id
 * @property string $name_en
 * @property string $name_bn
 * @property bool $is_active
 */
class BdLocation extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BdLocationType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<BdLocation, $this>
     */
    public function parentLocation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<BdLocation, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @param  Builder<BdLocation>  $query
     * @return Builder<BdLocation>
     */
    public function scopeOfType(Builder $query, BdLocationType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * The bilingual name at `$locale` ('en' or 'bn'), for a display string or
     * a snapshot.
     */
    public function name(string $locale): string
    {
        return $locale === 'bn' ? $this->name_bn : $this->name_en;
    }
}
