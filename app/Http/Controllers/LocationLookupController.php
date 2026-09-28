<?php

namespace App\Http\Controllers;

use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Queries\BdLocationChildren;
use Illuminate\Http\JsonResponse;

/**
 * The Bangladesh location directory's cached child lookups, behind the
 * cascading Division -> District -> Upazila -> Union select every address
 * form uses (§39: never the whole directory in one page).
 *
 * Registered once under the `web` guard (`routes/web.php`) and once under
 * `auth:supplier` (`routes/supplier.php`) — the same controller class either
 * way, since reference data carries no owner to scope by.
 */
class LocationLookupController extends Controller
{
    public function __construct(protected BdLocationChildren $children) {}

    public function divisions(): JsonResponse
    {
        return response()->json(['data' => $this->children->topLevel()]);
    }

    public function children(string $type, string $sourceId): JsonResponse
    {
        $parentType = BdLocationType::tryFrom($type);

        abort_if($parentType === null, 404);

        return response()->json(['data' => $this->children->childrenOf($parentType, $sourceId)]);
    }
}
