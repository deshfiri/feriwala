<?php

namespace App\Domain\Location\Queries;

use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Models\BdLocation;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Bangladesh administrative directory as a storefront walks it to pick a
 * delivery area (P2 H4).
 *
 * The existing {@see BdLocation} hierarchy — Division, District, Upazila,
 * Union — and nothing invented beside it. Delivery is still charged at the
 * flat per-package website fee (H3); an area only describes where an order is
 * going.
 *
 * Identified publicly by the node's own natural key, never its database id:
 * `{type}:{source_parent_id}:{source_id}`, with `-` for a Division's missing
 * parent. That key is what the import treats as the node's identity and is
 * fixed once written, so it is stable across re-imports.
 */
class DeliveryAreas
{
    /** No level of the directory has more children than this. */
    public const MAX_RESULTS = 1000;

    /**
     * The active children of `$parent` (null for the Divisions); null when
     * `$parent` names no active area.
     *
     * @return list<array{id: string, type: string, name: array{en: string, bn: string}, has_children: bool}>|null
     */
    public function children(?string $parent): ?array
    {
        if ($parent === null) {
            return $this->rows(BdLocation::query()->ofType(BdLocationType::Division)->whereNull('parent_id'));
        }

        $node = $this->find($parent);

        if ($node === null) {
            return null;
        }

        return $this->rows(BdLocation::query()->where('parent_id', $node->id));
    }

    /**
     * The active area a public id names, or null.
     */
    public function find(string $publicId): ?BdLocation
    {
        $parts = explode(':', $publicId);

        if (count($parts) !== 3) {
            return null;
        }

        [$type, $parentSource, $source] = $parts;
        $type = BdLocationType::tryFrom($type);

        if ($type === null || $source === '' || strlen($source) > 32 || strlen($parentSource) > 32) {
            return null;
        }

        /** @var BdLocation|null $node */
        $node = BdLocation::query()
            ->ofType($type)
            ->where('source_id', $source)
            ->when(
                $parentSource === '-',
                fn ($query) => $query->whereNull('source_parent_id'),
                fn ($query) => $query->where('source_parent_id', $parentSource),
            )
            ->where('is_active', true)
            ->first();

        return $node;
    }

    public static function publicId(BdLocation $location): string
    {
        return $location->type->value.':'.($location->source_parent_id ?? '-').':'.$location->source_id;
    }

    /**
     * @param  Builder<BdLocation>  $query
     * @return list<array{id: string, type: string, name: array{en: string, bn: string}, has_children: bool}>
     */
    protected function rows(Builder $query): array
    {
        return array_values($query
            ->where('is_active', true)
            ->withCount(['children as active_children' => fn ($children) => $children->where('is_active', true)])
            ->orderBy('name_en')
            ->orderBy('id')
            ->limit(self::MAX_RESULTS)
            ->get()
            ->map(fn (BdLocation $location) => [
                'id' => self::publicId($location),
                'type' => $location->type->value,
                'name' => ['en' => $location->name_en, 'bn' => $location->name_bn],
                'has_children' => (int) $location->getAttribute('active_children') > 0,
            ])
            ->all());
    }
}
