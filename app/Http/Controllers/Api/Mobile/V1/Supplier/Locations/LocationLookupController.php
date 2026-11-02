<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Locations;

use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Queries\BdLocationChildren;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use Illuminate\Http\JsonResponse;

/**
 * The Bangladesh location directory behind the address forms' cascading
 * Division, District, Upazila and Union selects. Reference data: it carries no
 * owner to scope by.
 */
class LocationLookupController extends MobileController
{
    public function __construct(protected BdLocationChildren $children) {}

    /**
     * Divisions
     *
     * The top level of the location directory. `id` is what the address
     * endpoints take; `source_id` is what the next level's lookup takes.
     *
     * @response array{data: list<array{id: int, source_id: string, name_en: string, name_bn: string}>}
     */
    public function divisions(): JsonResponse
    {
        return new JsonResponse(['data' => $this->children->topLevel()]);
    }

    /**
     * Child locations
     *
     * The locations one level below the given one: `type` is the parent's level
     * (`division`, `district` or `upazila`) and `sourceId` its `source_id`.
     * Responds `404` for an unknown level; empty when the parent does not exist
     * or has no children.
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