<?php

namespace App\Http\Controllers;

use App\Services\Webhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    /**
     * A webhook is signed, not cookie-bearing: no session, no CSRF, no shop lookup.
     */
    public function __invoke(Request $request, Webhooks $webhooks): JsonResponse
    {
        $result = $webhooks->receive(
            $request->getContent(),
            $request->header('x-feriwala-signature'),
            $request->header('x-feriwala-timestamp'),
            $request->header('x-feriwala-delivery'),
            $request->header('x-feriwala-event'),
        );

        return response()->json($result['body'], $result['status']);
    }
}
