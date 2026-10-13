<?php

namespace App\Http\Controllers\Api\Storefront\V1;

use App\Domain\Location\Queries\DeliveryAreas;
use App\Domain\Website\Api\StorefrontError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The areas a customer can pick a delivery address from (P2 H4, contract §5.5).
 *
 * The national directory, the same for every website: no account, website or
 * pricing data is in it, so a storefront may keep it for an hour. Delivery is
 * charged at the flat per-package fee whatever area is picked (H3).
 */
class DeliveryAreaController extends StorefrontController
{
    public function __construct(
        protected DeliveryAreas $areas,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $parent = $request->query('parent');

        if ($parent !== null && (! is_string($parent) || strlen($parent) > 80)) {
            return StorefrontError::respond($request, 422, 'validation_failed', 'parent is not a delivery area.', ['field' => 'parent']);
        }

        $children = $this->areas->children($parent === '' ? null : $parent);

        if ($children === null) {
            return StorefrontError::respond($request, 404, 'not_found', 'No such delivery area.');
        }

        $response = new JsonResponse(['data' => $children]);
        $response->headers->set('Cache-Control', 'private, max-age=3600');

        return $response;
    }
}
