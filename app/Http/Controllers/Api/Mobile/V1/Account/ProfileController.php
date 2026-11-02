<?php

namespace App\Http\Controllers\Api\Mobile\V1\Account;

use App\Domain\Account\Queries\OwnDeletionRequest;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Account\ProfileUpdateRequest;
use App\Http\Resources\Mobile\Account\ProfileResource;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in person's own profile (the web "Profile" settings screen).
 *
 * Self-scoped: it acts on the token's owner and takes no identifier. Reachable
 * before the business is activated.
 */
class ProfileController extends MobileController
{
    /**
     * Show my profile
     *
     * The person's name, email, mobile and verification state, plus their latest
     * open or refused account-deletion request (`deletion_request`, null when
     * there is none).
     *
     * @response array{data: ProfileResource, must_verify_email: bool, deletion_request: array{id: string, status: string, reason: string, requested_at: string, decision_note: string|null}|null}
     */
    public function show(Request $request, OwnDeletionRequest $deletion): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse([
            'data' => new ProfileResource($user),
            'must_verify_email' => $user instanceof MustVerifyEmail,
            'deletion_request' => $deletion->for($user),
        ]);
    }

    /**
     * Update my profile
     *
     * Changes the name and email address. Changing the email clears the email
     * verification; use `POST /verification/email/resend` to confirm the new one.
     * `422` when the email is taken.
     */
    public function update(ProfileUpdateRequest $request): ProfileResource
    {
        /** @var User $user */
        $user = $request->user();

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return new ProfileResource($user);
    }
}
