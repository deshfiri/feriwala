<?php

namespace App\Http\Controllers\Api\Mobile\V1\PayoutMethods;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Payout\Actions\ArchivePayoutMethod;
use App\Domain\Payout\Actions\SavePayoutMethod;
use App\Domain\Payout\Actions\SetDefaultPayoutMethod;
use App\Domain\Payout\Enums\PayoutMethodType;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Exceptions\PayoutMethodRefused;
use App\Domain\Payout\Models\PayoutMethod;
use App\Domain\Payout\Queries\PayoutMethodsForOwner;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\PayoutMethods\ArchivePayoutMethodRequest;
use App\Http\Requests\Mobile\PayoutMethods\SavePayoutMethodRequest;
use App\Http\Resources\Mobile\PayoutMethods\PayoutMethodResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The account's own payout methods (D25): where its withdrawals are paid.
 *
 * Self-scoped by query: a method is looked up among this account's own before
 * its identifier is used, so another account's method is a `404`. The account
 * number is encrypted at rest and never leaves the server; only its masked form
 * is returned. Changing a method needs the person to run the business and, for a
 * change or an archive, their password.
 */
class PayoutMethodController extends MobileController
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected PayoutMethodsForOwner $methods,
        protected SavePayoutMethod $save,
        protected SetDefaultPayoutMethod $setDefault,
        protected ArchivePayoutMethod $archive,
    ) {}

    /**
     * List payout methods
     *
     * The account's payout methods, default first, archived ones included
     * (`is_active` says which). `types` describes each kind of method and the
     * `fields` its `details` must carry; `can.create` says whether the caller may
     * add one.
     *
     * @response array{data: list<PayoutMethodResource>, types: list<array{value: string, label: string, fields: list<string>}>, can: array{create: bool}}
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $account = $this->businessAccountFor($request);

        return PayoutMethodResource::collection($this->methods->forOwner(PayoutOwnerType::BusinessAccount, $account->id))
            ->additional([
                'types' => array_map(
                    fn (PayoutMethodType $case) => ['value' => $case->value, 'label' => $case->label(), 'fields' => $case->detailFields()],
                    PayoutMethodType::cases(),
                ),
                'can' => ['create' => $request->user()?->can('create', PayoutMethod::class) ?? false],
            ]);
    }

    /**
     * Add a payout method
     *
     * Needs the caller's password. `403` unless the caller runs the business; `422`
     * for a wrong password, missing or mismatched details, an unknown bank or
     * branch, or the same account number registered twice (on `details`).
     */
    public function store(SavePayoutMethodRequest $request): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        Gate::authorize('create', PayoutMethod::class);

        $input = $request->payload();

        try {
            $method = $this->save->handle(
                ownerType: PayoutOwnerType::BusinessAccount,
                ownerId: $account->id,
                type: $input['type'],
                label: $input['label'],
                details: $input['details'],
                bdBankId: $input['bd_bank_id'],
                bdBankBranchId: $input['bd_bank_branch_id'],
                makeDefault: $input['is_default'],
            );
        } catch (PayoutMethodRefused $refused) {
            throw ValidationException::withMessages(['details' => $refused->getMessage()]);
        }

        return (new PayoutMethodResource($method->load(['bank', 'branch'])))->response()->setStatusCode(201);
    }

    /**
     * Replace a payout method
     *
     * Sends the whole method again and needs the caller's password. `404` for a
     * method that is not this account's; otherwise as adding one.
     */
    public function update(SavePayoutMethodRequest $request, string $method): PayoutMethodResource
    {
        $account = $this->businessAccountFor($request);
        $existing = $this->methodFor($account->id, $method);

        Gate::authorize('update', $existing);

        $input = $request->payload();

        try {
            $saved = $this->save->handle(
                ownerType: PayoutOwnerType::BusinessAccount,
                ownerId: $account->id,
                type: $input['type'],
                label: $input['label'],
                details: $input['details'],
                bdBankId: $input['bd_bank_id'],
                bdBankBranchId: $input['bd_bank_branch_id'],
                makeDefault: $input['is_default'],
                existing: $existing,
            );
        } catch (PayoutMethodRefused $refused) {
            throw ValidationException::withMessages(['details' => $refused->getMessage()]);
        }

        return new PayoutMethodResource($saved->load(['bank', 'branch']));
    }

    /**
     * Make a payout method the default
     *
     * `404` for a method that is not this account's; `403` unless the caller runs the business.
     */
    public function setDefault(Request $request, string $method): PayoutMethodResource
    {
        $existing = $this->methodFor($this->businessAccountFor($request)->id, $method);

        Gate::authorize('setDefault', $existing);

        $this->setDefault->handle($existing);

        return new PayoutMethodResource($existing->refresh()->load(['bank', 'branch']));
    }

    /**
     * Archive a payout method
     *
     * Stops the method being used for new withdrawals; withdrawals already
     * requested keep what they were paid to. Needs the caller's password. `404`
     * for a method that is not this account's; `403` unless the caller runs the business.
     */
    public function archive(ArchivePayoutMethodRequest $request, string $method): PayoutMethodResource
    {
        $existing = $this->methodFor($this->businessAccountFor($request)->id, $method);

        Gate::authorize('archive', $existing);

        $this->archive->handle($existing);

        return new PayoutMethodResource($existing->refresh()->load(['bank', 'branch']));
    }

    protected function methodFor(int $accountId, string $publicId): PayoutMethod
    {
        $method = $this->methods->findForOwner(PayoutOwnerType::BusinessAccount, $accountId, $publicId);

        abort_if($method === null, 404);

        return $method;
    }
}
