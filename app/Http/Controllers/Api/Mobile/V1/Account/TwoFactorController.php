<?php

namespace App\Http\Controllers\Api\Mobile\V1\Account;

use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Account\ConfirmTwoFactorRequest;
use App\Http\Requests\Mobile\Account\CurrentPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Authenticator-app two-factor authentication, through Fortify's own actions.
 *
 * Every call that reveals or removes the second factor takes the
 * `current_password`: the web's session-based password confirmation has no
 * equivalent for a token client.
 */
class TwoFactorController extends MobileController
{
    /**
     * Start two-factor setup
     *
     * Requires `current_password`. Generates a secret and eight recovery codes and
     * returns what the app needs to show: the `secret_key`, the `qr_code_url`
     * (an `otpauth://` link) and the `qr_code_svg`. Two-factor stays off until
     * the first code is confirmed with `POST /security/two-factor/confirm`.
     * Calling it again before confirming starts over with a new secret. `409`
     * with `code: two_factor_already_enabled` when it is already on.
     *
     * @response 201 array{data: array{secret_key: string, qr_code_url: string, qr_code_svg: string, recovery_codes: list<string>}}
     */
    public function enable(CurrentPasswordRequest $request, EnableTwoFactorAuthentication $enable): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return new JsonResponse([
                'code' => 'two_factor_already_enabled',
                'message' => __('Two-factor authentication is already enabled.'),
            ], HttpResponse::HTTP_CONFLICT);
        }

        $enable($user, force: true);

        $user->refresh();

        return new JsonResponse(['data' => [
            'secret_key' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            'qr_code_url' => $user->twoFactorQrCodeUrl(),
            'qr_code_svg' => $user->twoFactorQrCodeSvg(),
            'recovery_codes' => $user->recoveryCodes(),
        ]], HttpResponse::HTTP_CREATED);
    }

    /**
     * Confirm two-factor setup
     *
     * Finishes setup with the six-digit `code` the authenticator app currently
     * shows. From now on signing in asks for a second factor. `422` on `code` when
     * it is wrong or setup was never started.
     *
     * @response array{data: array{two_factor_enabled: true}}
     */
    public function confirm(ConfirmTwoFactorRequest $request, ConfirmTwoFactorAuthentication $confirm): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $confirm($user, $request->validated('code'));

        return new JsonResponse(['data' => ['two_factor_enabled' => true]]);
    }

    /**
     * Turn two-factor off
     *
     * Requires `current_password`. Removes the secret and the recovery codes. A
     * role that makes two-factor mandatory will be refused the admin panel until
     * it is set up again.
     */
    public function disable(CurrentPasswordRequest $request, DisableTwoFactorAuthentication $disable): Response
    {
        $disable($request->user());

        return response()->noContent();
    }

    /**
     * Regenerate recovery codes
     *
     * Requires `current_password`. Replaces all eight recovery codes (the old ones
     * stop working) and returns the new ones. `409` with `code: two_factor_not_enabled`
     * when two-factor is not on.
     *
     * @response array{data: array{recovery_codes: list<string>}}
     */
    public function regenerateRecoveryCodes(CurrentPasswordRequest $request, GenerateNewRecoveryCodes $generate): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return new JsonResponse([
                'code' => 'two_factor_not_enabled',
                'message' => __('Two-factor authentication is not enabled.'),
            ], HttpResponse::HTTP_CONFLICT);
        }

        $generate($user);

        return new JsonResponse(['data' => ['recovery_codes' => $user->refresh()->recoveryCodes()]]);
    }
}
