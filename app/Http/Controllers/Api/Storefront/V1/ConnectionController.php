<?php

namespace App\Http\Controllers\Api\Storefront\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a storefront can learn about its own connection (contract §9, P5-28).
 *
 * Its credential's status and scopes, its rate limits and when it last
 * synchronised — enough to diagnose "why is my shop out of date" without asking
 * a person. Nothing about the partner's account, wallet or package.
 */
class ConnectionController extends StorefrontController
{
    public function __invoke(Request $request): JsonResponse
    {
        $website = $this->website($request);
        $credential = $this->credential($request);

        return new JsonResponse([
            'website' => [
                'id' => $website->public_id,
                'status' => $website->status->value,
                'host' => $website->host(),
            ],
            'credential' => [
                'key_id' => $credential->key_id,
                'scopes' => $credential->scopes,
                'rotated_at' => $credential->rotated_at?->toIso8601String(),
                'previous_secret_expires_at' => $credential->previous_secret_expires_at?->toIso8601String(),
            ],
            'rate_limits' => config('website.api.rate_limits'),
            'last_synced_at' => $website->last_synced_at?->toIso8601String(),
            'connection_health' => $website->connection_health->value,
        ]);
    }
}
