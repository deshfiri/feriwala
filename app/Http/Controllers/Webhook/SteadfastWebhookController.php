<?php

namespace App\Http\Controllers\Webhook;

use App\Domain\Courier\Actions\ReceiveSteadfastWebhook;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Http\Controllers\Controller;
use App\Integrations\Courier\CourierManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Steadfast's status callback.
 *
 * Authenticated by the `Authorization: Bearer <token>` Banij generated and
 * Steadfast was given, compared whole and in constant time, **before** the body
 * is looked at. Then: a size cap, JSON only, and the five fields Steadfast's
 * package documents. The body is never logged and never stored.
 *
 * What the caller learns is deliberately thin: an unmatched or duplicate event
 * is the same success as a processed one, so this cannot be used to probe which
 * invoices exist, and Steadfast does not retry something that can never succeed.
 */
class SteadfastWebhookController extends Controller
{
    /** Bytes. A status push is a few hundred. */
    public const MAX_BODY = 16384;

    public function __invoke(Request $request, CourierManager $couriers, ReceiveSteadfastWebhook $receive): JsonResponse
    {
        if (! $couriers->driver(CourierProviderCode::Steadfast)->verifyWebhookSignature($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY) {
            return response()->json(['error' => 'Payload too large'], 413);
        }

        $payload = json_decode($body, true);

        if (! $request->isJson() || ! is_array($payload)) {
            return response()->json(['error' => 'Expected a JSON body'], 400);
        }

        $validator = Validator::make($payload, [
            'consignment_id' => ['required', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'invoice' => ['required', 'string', 'max:64'],
            'status' => ['required', 'string', 'max:64'],
            'cod_amount' => ['nullable'],
            'updated_at' => ['required', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            // Which fields, never what they held.
            return response()->json(['error' => 'Invalid payload', 'fields' => array_keys($validator->errors()->toArray())], 400);
        }

        $receive->handle([
            'consignment_id' => (string) $payload['consignment_id'],
            'invoice' => $payload['invoice'],
            'status' => $payload['status'],
            'updated_at' => $payload['updated_at'],
        ], $body);

        return response()->json(['status' => 'success']);
    }
}
