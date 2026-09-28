<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Address\Actions\ArchiveSharedAddress;
use App\Domain\Address\Actions\SaveSharedAddress;
use App\Domain\Address\Actions\SetDefaultSharedAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Enums\ClientAddressType;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Address\Queries\SharedAddressesForOwner;
use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Models\BdLocation;
use App\Domain\Location\Rules\ValidBdLocationHierarchy;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A `BusinessAccount`'s own business/operational address book (§31.3,
 * Location Directory + Shared Address module).
 *
 * Self-scoped through the signed-in person's own account, the same as
 * {@see WebsiteController} — no address appears in any URL, and one is
 * found among this account's or not at all.
 */
class AddressController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected SharedAddressesForOwner $addresses,
        protected SaveSharedAddress $save,
        protected SetDefaultSharedAddress $setDefault,
        protected ArchiveSharedAddress $archive,
    ) {}

    public function index(Request $request): Response
    {
        $account = $this->businessAccountFor($request);

        return Inertia::render('erp/addresses/index', [
            'addresses' => $this->addresses->forOwner(AddressOwnerType::BusinessAccount, $account->id)
                ->map(fn (SharedAddress $address) => $this->row($address))
                ->values(),
            'types' => $this->typeOptions(),
            'can' => [
                'create' => $request->user()?->can('create', SharedAddress::class) ?? false,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        Gate::authorize('create', SharedAddress::class);

        $validated = $this->validated($request);

        $this->save->handle(
            ownerType: AddressOwnerType::BusinessAccount,
            ownerId: $account->id,
            type: $validated['type'],
            contactName: $validated['contact_name'],
            contactMobile: $validated['contact_mobile'],
            divisionId: $validated['division_id'],
            districtId: $validated['district_id'],
            upazilaId: $validated['upazila_id'],
            unionId: $validated['union_id'],
            detailedAddress: $validated['detailed_address'],
            landmark: $validated['landmark'],
            postcode: $validated['postcode'],
            makeDefault: $validated['is_default'],
        );

        return back()->with('success', __('address.flash.created'));
    }

    public function update(Request $request, string $address): RedirectResponse
    {
        $account = $this->businessAccountFor($request);
        $existing = $this->addressFor($account->id, $address);

        Gate::authorize('update', $existing);

        $validated = $this->validated($request);

        $this->save->handle(
            ownerType: AddressOwnerType::BusinessAccount,
            ownerId: $account->id,
            type: $validated['type'],
            contactName: $validated['contact_name'],
            contactMobile: $validated['contact_mobile'],
            divisionId: $validated['division_id'],
            districtId: $validated['district_id'],
            upazilaId: $validated['upazila_id'],
            unionId: $validated['union_id'],
            detailedAddress: $validated['detailed_address'],
            landmark: $validated['landmark'],
            postcode: $validated['postcode'],
            makeDefault: $validated['is_default'],
            existing: $existing,
        );

        return back()->with('success', __('address.flash.updated'));
    }

    public function setDefault(Request $request, string $address): RedirectResponse
    {
        $account = $this->businessAccountFor($request);
        $existing = $this->addressFor($account->id, $address);

        Gate::authorize('setDefault', $existing);

        $this->setDefault->handle($existing);

        return back()->with('success', __('address.flash.default_set'));
    }

    public function archive(Request $request, string $address): RedirectResponse
    {
        $account = $this->businessAccountFor($request);
        $existing = $this->addressFor($account->id, $address);

        Gate::authorize('archive', $existing);

        $this->archive->handle($existing);

        return back()->with('success', __('address.flash.archived'));
    }

    protected function addressFor(int $accountId, string $publicId): SharedAddress
    {
        $address = $this->addresses->findForOwner(AddressOwnerType::BusinessAccount, $accountId, $publicId);

        abort_if($address === null, 404);

        return $address;
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(AddressOwnerType::BusinessAccount->allowedAddressTypes())],
            'contact_name' => ['required', 'string', 'max:150'],
            'contact_mobile' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'division_id' => ['required', 'integer', new ValidBdLocationHierarchy(BdLocationType::Division)],
            'district_id' => ['required', 'integer', new ValidBdLocationHierarchy(BdLocationType::District)],
            'upazila_id' => ['required', 'integer', new ValidBdLocationHierarchy(BdLocationType::Upazila)],
            'union_id' => ['nullable', 'integer', new ValidBdLocationHierarchy(BdLocationType::Union)],
            'detailed_address' => ['required', 'string', 'max:500'],
            'landmark' => ['nullable', 'string', 'max:150'],
            'postcode' => ['nullable', 'string', 'max:16'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        return [
            ...$validated,
            'union_id' => $validated['union_id'] ?? null,
            'landmark' => $validated['landmark'] ?? null,
            'postcode' => $validated['postcode'] ?? null,
            'is_default' => (bool) ($validated['is_default'] ?? false),
        ];
    }

    /**
     * The label lives client-side (`t('address.types.' + value)`), so this
     * app can show it in whichever language the page is already rendered in.
     *
     * @return list<string>
     */
    protected function typeOptions(): array
    {
        return array_map(fn (ClientAddressType $type) => $type->value, ClientAddressType::cases());
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(SharedAddress $address): array
    {
        return [
            'id' => $address->public_id,
            'type' => $address->type,
            'is_active' => $address->status === AddressStatus::Active,
            'is_default' => $address->is_default,
            'contact_name' => $address->contact_name,
            'contact_mobile' => $address->contact_mobile,
            'detailed_address' => $address->detailed_address,
            'landmark' => $address->landmark,
            'postcode' => $address->postcode,
            'location_snapshot' => $address->location_snapshot,
            'location' => [
                'division' => $this->locationOption($address->division),
                'district' => $this->locationOption($address->district),
                'upazila' => $this->locationOption($address->upazila),
                'union' => $this->locationOption($address->union),
            ],
        ];
    }

    /**
     * @return array{id: int, source_id: string, name_en: string, name_bn: string}|null
     */
    protected function locationOption(?BdLocation $location): ?array
    {
        return $location === null ? null : [
            'id' => $location->id,
            'source_id' => $location->source_id,
            'name_en' => $location->name_en,
            'name_bn' => $location->name_bn,
        ];
    }
}
