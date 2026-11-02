<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\PayoutMethods;

use App\Domain\Payout\Actions\ArchivePayoutMethod;
use App\Domain\Payout\Actions\SavePayoutMethod;
use App\Domain\Payout\Actions\SetDefaultPayoutMethod;
use App\Domain\Payout\Enums\PayoutMethodType;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Exceptions\PayoutMethodRefused;
use App\Domain\Payout\Models\PayoutMethod;
use App\Domain\Payout\Queries\PayoutMethodsForOwner;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Supplier\PayoutMethods\ConfirmPasswordRequest;
use App\Http\Requests\Mobile\Supplier\PayoutMethods\SavePayoutMethodRequest;
use App\Http\Resources\Mobile\Supplier\PayoutMethods\SupplierPayoutMethodResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * A Supplier's own payout methods (D25, P13-24), on the shared payout-method
 * table.
 *
 * Never returns an unmasked account number. Creating, replacing or archiving a
 * method asks for the current password in the request, as the web does. Another
 * supplier's method is a `404`. Closed to a supplier that is not yet approved
 * (`403 supplier_not_operational`).
 */
class PayoutMethodController extends MobileController
{
    public function __construct(
        protected PayoutMethodsForOwner $methods,
        protected SavePayoutMethod $save,
        protected SetDefaultPayoutMethod $setDefault,
        protected ArchivePayoutMethod $archive,
    ) {}

    /**
     * Payout methods
     *
     * The supplier's payout methods, default first, with the account number
     * masked. `types` lists each kind of method with the `fields` its `details`
     * object takes.
     *
     * @response array{data: list<SupplierPayoutMethodResource>, types: list<array{value: string, label: string, fields: list<string>}>}
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return SupplierPayoutMethodResource::collection(
            $this->methods->forOwner(PayoutOwnerType::Supplier, $this->supplier($request)->id),
        )->additional([
            'types' => array_map(
                fn (PayoutMethodType $case) => ['value' => $case->value, 'label' => $case->label(), 'fields' => $case->detailFields()],
                PayoutMethodType::cases(),
            ),
        ]);
    }

    /**
     * Add a payout method
     *
     * Needs the current password. For a bank account, `bank_code` and
     * `routing_number` come from the bank lookups and `details` must carry
     * `confirm_account_number`. `422` on any invalid field, a bank and branch
     * that do not belong together, or an account already registered.
     */
    public function store(SavePayoutMethodRequest $request): JsonResponse
    {
        $supplier = $this->supplier($request);

        $method = $this->saveFrom($request, $supplier);

        return (new SupplierPayoutMethodResource($method))->response()->setStatusCode(201);
    }

    /**
     * Replace a payout method
     *
     * Needs the current password. Rewrites one of the supplier's own methods;
     * withdrawals already made keep the details they were requested with. `404`
     * for any other id.
     */
    public function update(SavePayoutMethodRequest $request, string $method): SupplierPayoutMethodResource
    {
        $supplier = $this->supplier($request);
        $existing = $this->methodFor($supplier, $method);

        return new SupplierPayoutMethodResource($this->saveFrom($request, $supplier, $existing));
    }

    /**
     * Make a payout method the default
     *
     * `404` for any other id.
     */
    public function setDefault(Request $request, string $method): SupplierPayoutMethodResource
    {
        $existing = $this->methodFor($this->supplier($request), $method);

        return new SupplierPayoutMethodResource($this->setDefault->handle($existing));
    }

    /**
     * Archive a payout method
     *
     * Needs the current password. Retires the method; it is never deleted.
     * `404` for any other id.
     */
    public function archive(ConfirmPasswordRequest $request, string $method): Response
    {
        $existing = $this->methodFor($this->supplier($request), $method);

        $this->archive->handle($existing);

        return response()->noContent();
    }

    protected function saveFrom(SavePayoutMethodRequest $request, Supplier $supplier, ?PayoutMethod $existing = null): PayoutMethod
    {
        $payload = $request->payload();

        try {
            return $this->save->handle(
                ownerType: PayoutOwnerType::Supplier,
                ownerId: $supplier->id,
                type: $payload['type'],
                label: $payload['label'],
                details: $payload['details'],
                bdBankId: $payload['bd_bank_id'],
                bdBankBranchId: $payload['bd_bank_branch_id'],
                makeDefault: $payload['is_default'],
                existing: $existing,
            );
        } catch (PayoutMethodRefused $refused) {
            throw ValidationException::withMessages(['details' => $refused->getMessage()]);
        }
    }

    protected function supplier(Request $request): Supplier
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        return $supplier;
    }

    protected function methodFor(Supplier $supplier, string $publicId): PayoutMethod
    {
        $method = $this->methods->findForOwner(PayoutOwnerType::Supplier, $supplier->id, $publicId);

        abort_if($method === null, 404);

        return $method;
    }
}