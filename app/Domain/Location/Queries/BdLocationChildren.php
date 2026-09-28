<?php

namespace App\Domain\Location\Queries;

use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Models\BdLocation;
use App\Domain\Location\Models\BdLocationImport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Cached lookups behind the address forms' cascading Division -> District ->
 * Upazila -> Union selects (§39: never send the whole directory on every
 * page).
 *
 * Cached under the latest completed import's id, so a re-import invalidates
 * every cached key automatically — nothing here ever flushes a key by hand.
 * Bilingual: one cached payload carries both `name_en` and `name_bn`, so a
 * locale switch never needs a second round trip or a second cache entry.
 */
class BdLocationChildren
{
    /**
     * @return list<array{id: int, source_id: string, name_en: string, name_bn: string}>
     */
    public function topLevel(): array
    {
        return Cache::remember(
            $this->cacheKey('division', 'root'),
            now()->addDay(),
            fn () => $this->rows(BdLocation::query()->ofType(BdLocationType::Division)->where('is_active', true)),
        );
    }

    /**
     * The children of the `$parentType` node whose `source_id` is
     * `$parentSourceId` — an empty list when the parent does not exist, is
     * inactive, or is a Union (which has no children).
     *
     * @return list<array{id: int, source_id: string, name_en: string, name_bn: string}>
     */
    public function childrenOf(BdLocationType $parentType, string $parentSourceId): array
    {
        $childType = $parentType->child();

        if ($childType === null) {
            return [];
        }

        return Cache::remember(
            $this->cacheKey($parentType->value, $parentSourceId),
            now()->addDay(),
            function () use ($parentType, $childType, $parentSourceId) {
                $parent = BdLocation::query()
                    ->ofType($parentType)
                    ->where('source_id', $parentSourceId)
                    ->where('is_active', true)
                    ->first();

                if ($parent === null) {
                    return [];
                }

                return $this->rows(BdLocation::query()->ofType($childType)->where('parent_id', $parent->id)->where('is_active', true));
            },
        );
    }

    /**
     * @param  Builder<BdLocation>  $query
     * @return list<array{id: int, source_id: string, name_en: string, name_bn: string}>
     */
    protected function rows(Builder $query): array
    {
        return array_values($query->orderBy('name_en')->get()
            ->map(fn (BdLocation $location) => [
                'id' => $location->id,
                'source_id' => $location->source_id,
                'name_en' => $location->name_en,
                'name_bn' => $location->name_bn,
            ])
            ->all());
    }

    protected function cacheKey(string $parentType, string $parentKey): string
    {
        return sprintf('bd_locations.v%d.%s.%s', BdLocationImport::currentVersion(), $parentType, $parentKey);
    }
}
