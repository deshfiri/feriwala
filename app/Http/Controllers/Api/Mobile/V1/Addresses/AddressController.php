<?php

namespace App\Http\Controllers\Api\Mobile\V1\Addresses;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Address\Actions\ArchiveSharedAddress;
use App\Domain\Address\Actions\SaveSharedAddress;
use App\Domain\Address\Actions\SetDefaultSharedAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\ClientAddressType;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Address\Queries\SharedAddressesForOwner;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Addresses\SaveAddressRequest;
use App\Http\Resources\Mobile\Addresses\AddressResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The account's own business/operational address book (§31.3).
 *
 * Self-scoped by query: an address is looked up among this account's own before
 * its identifier is used, so another account's address is a `404`. Changing the
 * book needs the person to run the business (`403` otherwise).
 */
class AddressController extends MobileController
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected SharedAddressesForOwner $addresses,
        protected SaveSharedAddress $save,
        protected SetDefaultSharedAddress $setDefault,
        protected ArchiveSharedAddress $archive,
    ) {}

    /**
     * List addresses
     *
     * The account's addresses, default first, archived ones included
     * (`is_active` says which). `types` lists the values an address `type` may
     * take; `can.create` says whether the caller may add one.
     *
     * @response array{data: list<AddressResource>, types: list<string>, can: array{create: bool}}
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $account = $this->businessAccountFor($request);

        return AddressResource::collection($this->addresses->forOwner(AddressOwnerType::BusinessAccount, $account->id))
            ->additional([
                'types' => array_map(fn (ClientAddressType $type) => $type->value, ClientAddressType::cases()),
                'can' => ['create' => $request->user()?->can('create', SharedAddress::class) ?? false],
            ]);
    }

    /**
     * Add an address
     *
     * `403` unless the caller runs the business; `422` for a place that is not
     * valid or does not sit inside the one above it, or a malformed mobile number.
     */
    public function store(SaveAddressRequest $request): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        Gate::authorize('create', SharedAddress::class);

        $input = $request->payload();

        $address = $this->save->handle(
            ownerType: AddressOwnerType::BusinessAccount,
            ownerId: $account->id,
            type: $input['type'],
            contactName: $input['contact_name'],
            contactMobile: $input['contact_mobile'],
            divisionId: $input['division_id'],
            districtId: $input['district_id'],
            upazilaId: $input['upazila_id'],
            unionId: $input['union_id'],
            detailedAddress: $input['detailed_address'],
            landmark: $input['landmark'],
            postcode: $input['postcode'],
            makeDefault: $input['is_default'],
        );

        return (new AddressResource($this->loaded($address)))->response()->setStatusCode(201);
    }

    /**
     * Replace an address
     *
     * Sends the whole address again. `404` for an address that is not this
     * account's; otherwise as adding one.
     */
    public function update(SaveAddressRequest $request, string $address): AddressResource
    {
        $account = $this->businessAccountFor($request);
        $existing = $this->addressFor($account->id, $address);

        Gate::authorize('update', $existing);

        $input = $request->payload();

        $saved = $this->save->handle(
            ownerType: AddressOwnerType::BusinessAccount,
            ownerId: $account->id,
            type: $input['type'],
            contactName: $input['contact_name'],
            contactMobile: $input['contact_mobile'],
            divisionId: $input['division_id'],
            districtId: $input['district_id'],
            upazilaId: $input['upazila_id'],
            unionId: $input['union_id'],
            detailedAddress: $input['detailed_address'],
            landmark: $input['landmark'],
            postcode: $input['postcode'],
            makeDefault: $input['is_default'],
            existing: $existing,
        );

        return new AddressResource($this->loaded($saved));
    }

    /**
     * Make an address the default
     *
     * `404` for an address that is not this account's; `403` unless the caller runs the business.
     */
    public function setDefault(Request $request, string $address): AddressResource
    {
        $existing = $this->addressFor($this->businessAccountFor($request)->id, $address);

        Gate::authorize('setDefault', $existing);

        $this->setDefault->handle($existing);

        return new AddressResource($this->loaded($existing));
    }

    /**
     * Archive an address
     *
     * Takes the address out of use; it stays in the list with `is_active` false.
     * `404` for an address that is not this account's; `403` unless the caller runs the business.
     */
    public function archive(Request $request, string $address): AddressResource
    {
        $existing = $this->addressFor($this->businessAccountFor($request)->id, $address);

        Gate::authorize('archive', $existing);

        $this->archive->handle($existing);

        return new AddressResource($this->loaded($existing));
    }

    protected function addressFor(int $accountId, string $publicId): SharedAddress
    {
        $address = $this->addresses->findForOwner(AddressOwnerType::BusinessAccount, $accountId, $publicId);

        abort_if($address === null, 404);

        return $address;
    }

    protected function loaded(SharedAddress $address): SharedAddress
    {
        return $address->refresh()->load(['division', 'district', 'upazila', 'union']);
    }
}
