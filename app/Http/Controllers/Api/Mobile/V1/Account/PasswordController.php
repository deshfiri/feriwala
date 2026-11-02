<?php

namespace App\Http\Controllers\Api\Mobile\V1\Account;

use App\Domain\Account\Actions\EndAuthenticatedSessions;
use App\Domain\Account\Models\AuthenticatedSession;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Account\PasswordUpdateRequest;
use App\Models\User;
use App\Notifications\Account\PasswordChanged;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Changing the password while signed in.
 */
class PasswordController extends MobileController
{
    /**
     * Change my password
     *
     * Requires the `current_password` (the mobile equivalent of the web's password
     * confirmation) and a new `password` that meets the policy. Everywhere else is
     * signed out: every other bearer token and every web session ends, this
     * device stays signed in. The person is told by email. `422` for a wrong
     * current password or a weak new one.
     */
    public function update(PasswordUpdateRequest $request, EndAuthenticatedSessions $endSessions): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->update(['password' => $request->validated('password')]);

        $current = $user->currentAccessToken();

        $user->tokens()
            ->when($current instanceof PersonalAccessToken, fn ($tokens) => $tokens->whereKeyNot($current->getKey()))
            ->delete();

        $endSessions->all($user, AuthenticatedSession::ENDED_PASSWORD_CHANGED);

        $user->notify(new PasswordChanged);

        return response()->noContent();
    }
}
