<?php

namespace App\Http\Controllers\Api\Mobile\V1\Auth;

use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Resources\Mobile\Auth\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The signed-in person and the devices that hold a token for them.
 *
 * A device is addressed by a `reference` derived from its token and the
 * application key, never by its database id, so one person cannot probe
 * another's by counting.
 */
class SessionController extends MobileController
{
    /**
     * Current user
     *
     * The person behind the bearer token, with the state of their business
     * account. Call this on app start to check the token is still good.
     */
    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user->load('businessAccount', 'roles'));
    }

    /**
     * Sign out
     *
     * Revokes the token this request was made with. The app should discard it.
     */
    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * Sign out everywhere
     *
     * Revokes every token the person holds, this device included.
     */
    public function logoutAll(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->tokens()->delete();

        return response()->noContent();
    }

    /**
     * Signed-in devices
     *
     * One entry per device holding a token, most recently used first.
     *
     * @response array{data: list<array{reference: string, name: string, is_current: bool, last_used_at: string|null, created_at: string, expires_at: string|null}>}
     */
    public function devices(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currentId = $user->currentAccessToken()->getKey();

        return new JsonResponse(['data' => $user->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'reference' => self::reference($token),
                'name' => $token->name,
                'is_current' => $token->getKey() === $currentId,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ])
            ->values()
            ->all()]);
    }

    /**
     * Sign out one device
     *
     * Revokes the token whose `reference` came from the devices list. A
     * reference that is not this person's finds nothing and answers `404`.
     */
    public function revokeDevice(Request $request, string $reference): Response
    {
        /** @var User $user */
        $user = $request->user();

        $token = $user->tokens()->get()
            ->first(fn (PersonalAccessToken $candidate) => hash_equals(self::reference($candidate), $reference));

        abort_if($token === null, 404);

        $token->delete();

        return response()->noContent();
    }

    private static function reference(PersonalAccessToken $token): string
    {
        return substr(hash_hmac('sha256', (string) $token->getKey(), (string) config('app.key')), 0, 24);
    }
}
