<?php

namespace App\Http\Controllers\Api\Mobile\V1\Auth;

use App\Domain\Account\Actions\IssueMobileAccessToken;
use App\Http\Controllers\Api\Mobile\V1\Auth\Concerns\RespondsWithAccessToken;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Auth\TwoFactorChallengeRequest;
use App\Http\Resources\Mobile\Auth\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * The second step of a mobile sign-in, for accounts with two-factor on.
 */
class TwoFactorChallengeController extends MobileController
{
    use RespondsWithAccessToken;

    public function __construct(
        protected TwoFactorAuthenticationProvider $provider,
        protected IssueMobileAccessToken $issueToken,
    ) {}

    /**
     * Where a pending challenge is held until it is answered or expires.
     */
    public static function cacheKey(string $challengeToken): string
    {
        return 'mobile:two-factor-challenge:'.hash('sha256', $challengeToken);
    }

    /**
     * Complete sign-in with a second factor
     *
     * Send the `challenge_token` from the login response together with either a
     * six-digit authenticator `code` or one `recovery_code`. A recovery code is
     * spent when used. The challenge lives for five minutes and is consumed by
     * a successful answer; five wrong answers a minute are refused with `429`.
     *
     * @unauthenticated
     *
     * @response array{two_factor_required: false, token: string, token_type: 'Bearer', expires_at: string|null, user: UserResource}
     */
    public function __invoke(TwoFactorChallengeRequest $request): JsonResponse
    {
        $key = self::cacheKey($request->validated('challenge_token'));
        $challenge = Cache::get($key);

        $user = is_array($challenge) ? User::query()->find($challenge['user']) : null;

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'challenge_token' => __('This sign-in has expired. Start again.'),
            ]);
        }

        if (! $this->answers($user, $request)) {
            throw ValidationException::withMessages([
                $request->filled('recovery_code') ? 'recovery_code' : 'code' => __('The provided two factor authentication code was invalid.'),
            ]);
        }

        Cache::forget($key);

        return $this->signedIn($this->issueToken, $user, $challenge['device_name']);
    }

    protected function answers(User $user, TwoFactorChallengeRequest $request): bool
    {
        if ($request->filled('recovery_code')) {
            $code = collect($user->recoveryCodes())
                ->first(fn (string $stored) => hash_equals($stored, (string) $request->input('recovery_code')));

            if ($code === null) {
                return false;
            }

            $user->replaceRecoveryCode($code);

            return true;
        }

        return $user->two_factor_secret !== null
            && $this->provider->verify(decrypt($user->two_factor_secret), (string) $request->input('code'));
    }
}
