<?php

namespace App\Http\Controllers\Api\Mobile\V1\Account;

use App\Domain\Account\Actions\RequestAccountDeletion;
use App\Domain\Account\Actions\WithdrawAccountDeletion;
use App\Domain\Account\Models\AccountDeletionRequest;
use App\Domain\Account\Queries\OwnDeletionRequest;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Account\DeletionRequestRequest;
use App\Http\Resources\Mobile\Account\DeletionRequestResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Asking to have your own account deleted.
 *
 * Nobody deletes their own account here: a person asks, confirms with their
 * password, and an authorised member of staff decides. They can change their mind
 * until it is decided. Self-scoped: it acts on the token's owner and takes no
 * identifier.
 */
class AccountDeletionController extends MobileController
{
    /**
     * My deletion request
     *
     * The latest pending or refused request, or `data: null` when there is none.
     *
     * @response array{data: array{id: string, status: string, reason: string, requested_at: string, decision_note: string|null}|null}
     */
    public function show(Request $request, OwnDeletionRequest $deletion): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse(['data' => $deletion->for($user)]);
    }

    /**
     * Request account deletion
     *
     * Requires `current_password` and a `reason` (5 to 1000 characters). Puts the
     * request in the staff queue; asking twice returns the open request rather
     * than a second one. Nothing is deleted until staff approve.
     */
    public function store(DeletionRequestRequest $request, RequestAccountDeletion $requestDeletion): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $deletion = $requestDeletion->handle($user, $request->validated('reason'));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return (new DeletionRequestResource($deletion))->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * Withdraw my deletion request
     *
     * Cancels the pending request. Withdrawing when none is open is not an error.
     */
    public function destroy(Request $request, WithdrawAccountDeletion $withdraw): Response
    {
        /** @var User $user */
        $user = $request->user();

        $pending = AccountDeletionRequest::query()->where('user_id', $user->id)->where('status', 'pending')->first();

        if ($pending !== null) {
            try {
                $withdraw->handle($pending, $user);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
            }
        }

        return response()->noContent();
    }
}
