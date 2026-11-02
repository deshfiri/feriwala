<?php

namespace App\Http\Controllers\Api\Mobile\V1\Locations;

use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Queries\BdLocationChildren;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use Illuminate\Http\JsonResponse;

/**
 * The Bangladesh location directory behind the address forms' cascading
 * Division, District, Upazila, Union selects (§39).
 *
 * Reference data shared by every account, so no owner scoping applies. Every
 * row carries both languages, so changing language needs no second request.
 * `id` is the value an address form submits back (`division_id`, `district_id`,
 * `upazila_id`, `union_id`); `source_id` is the directory's own identifier and
 * the value to put in the next level's lookup URL.
 */
class LocationController extends MobileController
{
    public function __construct(protected BdLocationChildren $children) {}

    /**
     * List divisions
     *
     * The top level of the directory, by English name.
     *
     * @response array{data: list<array{id: int, source_id: string, name_en: string, name_bn: string}>}
     */
    public function divisions(): JsonResponse
    {
        return new JsonResponse(['data' => $this->children->topLevel()]);
    }

    /**
     * List the places inside a place
     *
     * The children of one place: `type` is the level of the PARENT (`division`,
     * `district` or `upazila`) and `sourceId` its `source_id`, so a division's
     * districts, a district's upazilas, an upazila's unions. A parent that does not
     * exist, or a union (which has no children), gives an empty list. `404` for a
     * `type` that is not a level.
     *
     * @response array{data: list<array{id: int, source_id: string, name_en: string, name_bn: string}>}
     */
    public function children(string $type, string $sourceId): JsonResponse
    {
        $parentType = BdLocationType::tryFrom($type);

        abort_if($parentType === null, 404);

        return new JsonResponse(['data' => $this->children->childrenOf($parentType, $sourceId)]);
    }
}
