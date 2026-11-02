<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Addresses;

use App\Domain\Address\Actions\ArchiveSharedAddress;
use App\Domain\Address\Actions\SaveSharedAddress;
use App\Domain\Address\Actions\SetDefaultSharedAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\SupplierAddressType;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Address\Queries\SharedAddressesForOwner;
use App\Domain\Supplier\Models\Supplier;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Supplier\Addresses\SaveSupplierAddressRequest;
use App\Http\Resources\Mobile\Supplier\Addresses\SupplierAddressResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * A Supplier's own registered, pickup and return addresses (D25).
 *
 * Reached through the signed-in Supplier's own id before any public id is used,
 * so another supplier's address is a `404`. Closed to a supplier that is not
 * yet approved (`403 supplier_not_operational`), as on the web.
 */
class AddressController extends MobileController
{
    public function __construct(
        protected SharedAddressesForOwner $addresses,
        protected SaveSharedAddress $save,
        protected SetDefaultSharedAddress $setDefault,
        protected ArchiveSharedAddress $archive,
    ) {}

    /**
     * Addresses
     *
     * The supplier's addresses, default first. `types` lists the address types
     * the supplier may save.
     *
     * @response array{data: list<SupplierAddressResource>, types: list<string>}
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $supplier = $this->supplier($request);

        return SupplierAddressResource::collection(
            $this->addresses->forOwner(AddressOwnerType::Supplier, $supplier->id),
        )->additional([
            'types' => array_map(fn (SupplierAddressType $type) => $type->value, SupplierAddressType::cases()),
        ]);
    }

    /**
     * Add an address
     *
     * Saves a new address. The location ids come from the location lookups and
     * must form a valid chain (division, district, upazila, optionally union).
     * `422` on any invalid field.
     */
    public function store(SaveSupplierAddressRequest $request): JsonResponse
    {
        $supplier = $this->supplier($request);

        $address = $this->save->handle(...$this->arguments($request, $supplier));

        return (new SupplierAddressResource($address))->response()->setStatusCode(201);
    }

    /**
     * Replace an address
     *
     * Rewrites one of the supplier's own addresses. `404` for any other id.
     */
    public function update(SaveSupplierAddressRequest $request, string $address): SupplierAddressResource
    {
        $supplier = $this->supplier($request);
        $existing = $this->addressFor($supplier, $address);

        $saved = $this->save->handle(...$this->arguments($request, $supplier), existing: $existing);

        return new SupplierAddressResource($saved);
    }

    /**
     * Make an address the default
     *
     * Makes the address the default of its type. `404` for any other id.
     */
    public function setDefault(Request $request, string $address): SupplierAddressResource
    {
        $existing = $this->addressFor($this->supplier($request), $address);

        return new SupplierAddressResource($this->setDefault->handle($existing));
    }

    /**
     * Archive an address
     *
     * Retires the address; it is never deleted. `404` for any other id.
     */
    public function archive(Request $request, string $address): Response
    {
        $existing = $this->addressFor($this->supplier($request), $address);

        $this->archive->handle($existing);

        return response()->noContent();
    }

    protected function supplier(Request $request): Supplier
    {
        /** @var Supplier $supplier */
        $supplier = $request->user();

        return $supplier;
    }

    protected function addressFor(Supplier $supplier, string $publicId): SharedAddress
    {
        $address = $this->addresses->findForOwner(AddressOwnerType::Supplier, $supplier->id, $publicId);

        abort_if($address === null, 404);

        return $address;
    }

    /**
     * @return array<string, mixed>
     */
    protected function arguments(SaveSupplierAddressRequest $request, Supplier $supplier): array
    {
        $validated = $request->validated();

        return [
            'ownerType' => AddressOwnerType::Supplier,
            'ownerId' => $supplier->id,
            'type' => $validated['type'],
            'contactName' => $validated['contact_name'],
            'contactMobile' => $validated['contact_mobile'],
            'divisionId' => $validated['division_id'],
            'districtId' => $validated['district_id'],
            'upazilaId' => $validated['upazila_id'],
            'unionId' => $validated['union_id'] ?? null,
            'detailedAddress' => $validated['detailed_address'],
            'landmark' => $validated['landmark'] ?? null,
            'postcode' => $validated['postcode'] ?? null,
            'makeDefault' => (bool) ($validated['is_default'] ?? false),
        ];
    }
}