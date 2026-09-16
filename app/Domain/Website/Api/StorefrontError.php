<?php

namespace App\Domain\Website\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An error, in the one shape the contract allows (contract §4.5).
 *
 *     { "error": { "code", "message", "details", "request_id" } }
 *
 * `code` is stable and machine-readable; `message` is for a person and may
 * change. The request identifier is the one written to `api_logs`, so a partner
 * quoting it leads straight to the record.
 *
 * A message never carries another partner's data, an internal identifier, a
 * stack trace or SQL. That is why every error on this surface is built here
 * rather than left to the framework's default rendering.
 */
class StorefrontError
{
    /** The request attribute holding the identifier this request is logged under. */
    public const REQUEST_ID = 'storefront.request_id';

    /** The request attribute a middleware stamps with the code it refused on. */
    public const ERROR_CODE = 'storefront.error_code';

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function respond(
        Request $request,
        int $status,
        string $code,
        string $message,
        array $details = [],
        array $headers = [],
    ): JsonResponse {
        $request->attributes->set(self::ERROR_CODE, $code);

        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
                'request_id' => $request->attributes->get(self::REQUEST_ID),
            ],
        ], $status, $headers);
    }
}
