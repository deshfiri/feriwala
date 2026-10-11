<?php

namespace App\Http\Controllers\Webhook;

use App\Domain\ExternalStore\Actions\ReceiveExternalStoreWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications from partners' external stores (D29).
 *
 * The store is named by its public id in the URL; who sent it is proven only
 * by the signature, which {@see ReceiveExternalStoreWebhook} checks before
 * anything is written. An unknown store and a disconnected one answer the same
 * way, so this cannot be used to find out which stores exist.
 */
class ExternalStoreWebhookController extends Controller
{
    public function __invoke(Request $request, string $store, ReceiveExternalStoreWebhook $receive): JsonResponse
    {
        $headers = [
            'x-webhook-signature' => $request->header('X-Webhook-Signature'),
            'x-webhook-event-id' => $request->header('X-Webhook-Event-Id'),
        ];

        return match ($receive->handle($store, $request->getContent(), $headers)) {
            'accepted' => response()->json(['ok' => true]),
            'duplicate' => response()->json(['ok' => true, 'duplicate' => true]),
            'unauthorised' => response()->json(['ok' => false, 'error' => 'invalid signature'], 401),
            'malformed' => response()->json(['ok' => false, 'error' => 'malformed notification'], 422),
            'unknown_store' => response()->json(['ok' => false, 'error' => 'not found'], 404),
        };
    }
}
