<?php

namespace App\Http\Controllers\Api\Mobile\V1\Auth\Concerns;

use App\Domain\Account\Actions\IssueMobileAccessToken;
use App\Http\Resources\Mobile\Auth\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * The one shape of "you are signed in", shared by the password step and the
 * second-factor step so the app handles a single success response.
 */
trait RespondsWithAccessToken
{
    protected function signedIn(IssueMobileAccessToken $issueToken, User $user, string $deviceName): JsonResponse
    {
        $issued = $issueToken->handle($user, $deviceName);

        return new JsonResponse([
            'two_factor_required' => false,
            'token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['expires_at']?->toIso8601String(),
            'user' => new UserResource($user->load('businessAccount', 'roles')),
        ]);
    }
}
