<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Auth;

use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Supplier\Auth\SupplierLoginRequest;
use App\Http\Resources\Mobile\Supplier\Auth\SupplierResource;
use App\Support\Security\LoginThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\LoginRateLimiter;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Supplier sign-in from the mobile app (D25).
 *
 * A Supplier is a wholly separate account domain from a Client/Partner: its own
 * table, its own login, its own tokens. A supplier token only opens
 * `/supplier/*` routes and a user token never does (`mobile.access:supplier`).
 * The web lets a suspended supplier sign in to read its own status, so this
 * does too; only a closed one loses access.
 */
class SupplierSessionController extends MobileController
{
    public function __construct(
        protected LoginRateLimiter $throttle,
    ) {}

    /**
     * Supplier sign in
     *
     * Exchanges a supplier email and password for a bearer token, sent on every
     * later call as `Authorization: Bearer <token>`. Failed attempts are
     * throttled exactly as the main login is (5 per minute per email, 30 per
     * quarter-hour per address) and answer `429`.
     *
     * @unauthenticated
     *
     * @response array{token: string, token_type: 'Bearer', expires_at: string|null, supplier: SupplierResource}
     */
    public function store(SupplierLoginRequest $request): JsonResponse
    {
        if ($this->throttle->tooManyAttempts($request)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => $this->throttle->availableIn($request), 'minutes' => ceil($this->throttle->availableIn($request) / 60)]),
            ])->status(HttpResponse::HTTP_TOO_MANY_REQUESTS);
        }

        $supplier = Supplier::query()
            ->where('email', LoginThrottle::normalize($request->validated('email')))
            ->first();

        if (! $supplier instanceof Supplier || ! Hash::check($request->validated('password'), $supplier->password)) {
            $this->throttle->increment($request);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $this->throttle->clear($request);

        $supplier->tokens()->where('name', $request->validated('device_name'))->delete();
        $token = $supplier->createToken($request->validated('device_name'));

        return new JsonResponse([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'supplier' => new SupplierResource($supplier),
        ]);
    }

    /**
     * Current supplier
     *
     * The supplier behind the bearer token and where they stand in the approval
     * funnel. Call on app start to check the token is still good.
     */
    public function show(Request $request): SupplierResource
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        return new SupplierResource($supplier);
    }

    /**
     * Supplier sign out
     *
     * Revokes the token this request was made with.
     */
    public function destroy(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        $supplier->currentAccessToken()->delete();

        return response()->noContent();
    }
}
