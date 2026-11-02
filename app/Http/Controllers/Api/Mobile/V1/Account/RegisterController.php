<?php

namespace App\Http\Controllers\Api\Mobile\V1\Account;

use App\Actions\Fortify\CreateNewUser;
use App\Domain\Account\Actions\IssueMobileAccessToken;
use App\Domain\Account\Enums\Gender;
use App\Domain\Account\Models\AccountInvitation;
use App\Http\Controllers\Api\Mobile\V1\Auth\Concerns\RespondsWithAccessToken;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Account\RegisterRequest;
use App\Http\Resources\Mobile\Auth\UserResource;
use App\Support\Localization\Countries;
use App\Support\Security\PasswordPolicy;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opening a Feriwala account from the app (Â§5.1, Â§5.2).
 *
 * Registration only opens the account; it does not activate it. The same
 * {@see CreateNewUser} action the web registration runs does the work, and the
 * person is signed straight in with a bearer token.
 */
class RegisterController extends MobileController
{
    use RespondsWithAccessToken;

    public function __construct(
        protected CreateNewUser $createUser,
        protected IssueMobileAccessToken $issueToken,
    ) {}

    /**
     * Registration form options
     *
     * Everything the registration screen needs to build its form: the country and
     * gender pickers (the same lists the server validates against), the password
     * policy, and two optional prefills. Pass `ref` (a referral link's code) to
     * have it echoed back as `referral_code`, and `invitation` (a staff
     * invitation token) to learn which business the person is joining.
     *
     * @unauthenticated
     *
     * @response array{countries: list<array{value: string, label: string}>, default_country: string, genders: list<array{value: string, label: string}>, password_rules: string, referral_code: string|null, staff_invitation: array{token: string, account: string, role: string}|null}
     */
    public function options(Request $request): JsonResponse
    {
        $countries = app(Countries::class);

        return new JsonResponse([
            'countries' => $countries->options(),
            'default_country' => $countries->default(),
            'genders' => Gender::options(),
            'password_rules' => PasswordPolicy::hint(),
            'referral_code' => $request->string('ref')->toString() ?: null,
            'staff_invitation' => $this->staffInvitation($request),
        ]);
    }

    /**
     * Register
     *
     * Creates the person and their business account (status `registered`), then
     * signs them in: the response has the same shape as `POST /auth/login`, so the
     * app can go straight to onboarding. A verification email is sent. Both
     * `terms_accepted` and `privacy_accepted` must be true. Registering with a
     * valid staff `invitation` joins that business instead of opening a new one.
     *
     * Errors: `422` for invalid fields (including a taken email or mobile), `429`
     * when called too often.
     *
     * @unauthenticated
     *
     * @response 201 array{two_factor_required: false, token: string, token_type: 'Bearer', expires_at: string|null, user: UserResource}
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        $user = $this->createUser->create($request->safe()->except('device_name'));

        event(new Registered($user));

        return $this->signedIn($this->issueToken, $user, $request->validated('device_name'))
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * The invitation a visitor arrived with, so the screen can name the business.
     *
     * The token identifies the invitation and authorises nothing; acceptance
     * checks who registers.
     *
     * @return array{token: string, account: string, role: string}|null
     */
    protected function staffInvitation(Request $request): ?array
    {
        $token = $request->query('invitation');

        if (! is_string($token)) {
            return null;
        }

        $invitation = AccountInvitation::query()
            ->with('businessAccount')
            ->where('token', $token)
            ->live()
            ->first();

        if ($invitation === null) {
            return null;
        }

        return [
            'token' => $invitation->token,
            'account' => $invitation->businessAccount->name,
            'role' => $invitation->role->label(),
        ];
    }
}
