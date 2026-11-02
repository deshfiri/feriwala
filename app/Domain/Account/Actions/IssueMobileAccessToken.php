<?php

namespace App\Domain\Account\Actions;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Hands a signed-in person a bearer token for the mobile app.
 *
 * One token per device. Signing in again on a device that already holds one
 * replaces it instead of piling up, so "my devices" stays a list of phones
 * rather than a list of sign-ins.
 */
class IssueMobileAccessToken
{
    /**
     * @return array{token: string, expires_at: CarbonInterface|null}
     */
    public function handle(User $user, string $deviceName): array
    {
        $user->tokens()->where('name', $deviceName)->delete();

        $newToken = $user->createToken($deviceName);

        return [
            'token' => $newToken->plainTextToken,
            'expires_at' => $newToken->accessToken->expires_at,
        ];
    }
}
