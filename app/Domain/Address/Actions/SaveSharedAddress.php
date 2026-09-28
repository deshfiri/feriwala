<?php

namespace App\Domain\Address\Actions;

use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Location\Models\BdLocation;
use App\Domain\Location\Rules\ValidBdLocationHierarchy;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Create or update an address in the shared address book.
 *
 * The bilingual place names are resolved and frozen onto the row
 * (`location_snapshot`) once, here, from the ids the request validated
 * against {@see ValidBdLocationHierarchy} — never re-read from
 * `bd_locations` afterwards, so a later rename or deactivation there never
 * rewrites an address already on file.
 */
class SaveSharedAddress
{
    public function __construct(
        protected DatabaseManager $database,
        protected SetDefaultSharedAddress $setDefault,
    ) {}

    public function handle(
        AddressOwnerType $ownerType,
        int $ownerId,
        string $type,
        string $contactName,
        string $contactMobile,
        int $divisionId,
        int $districtId,
        int $upazilaId,
        ?int $unionId,
        string $detailedAddress,
        ?string $landmark,
        ?string $postcode,
        bool $makeDefault = false,
        ?SharedAddress $existing = null,
    ): SharedAddress {
        $address = $this->database->transaction(function () use (
            $ownerType, $ownerId, $type, $contactName, $contactMobile,
            $divisionId, $districtId, $upazilaId, $unionId,
            $detailedAddress, $landmark, $postcode, $existing,
        ) {
            $attributes = [
                'owner_type' => $ownerType->value,
                'owner_id' => $ownerId,
                'type' => $type,
                'contact_name' => $contactName,
                'contact_mobile' => $contactMobile,
                'division_id' => $divisionId,
                'district_id' => $districtId,
                'upazila_id' => $upazilaId,
                'union_id' => $unionId,
                'detailed_address' => $detailedAddress,
                'landmark' => $landmark,
                'postcode' => $postcode,
                'location_snapshot' => $this->resolveSnapshot($divisionId, $districtId, $upazilaId, $unionId),
            ];

            if ($existing !== null) {
                /** @var SharedAddress $locked */
                $locked = SharedAddress::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== AddressStatus::Active) {
                    throw new RuntimeException('An archived address cannot be edited.');
                }

                $locked->forceFill($attributes)->save();

                return $locked;
            }

            return SharedAddress::create([
                ...$attributes,
                'status' => AddressStatus::Active,
                'is_default' => false,
            ]);
        });

        return $makeDefault ? $this->setDefault->handle($address) : $address->refresh();
    }

    /**
     * @return array<string, array{en: string, bn: string}|null>
     */
    protected function resolveSnapshot(int $divisionId, int $districtId, int $upazilaId, ?int $unionId): array
    {
        $ids = array_values(array_filter([$divisionId, $districtId, $upazilaId, $unionId]));
        $locations = BdLocation::query()->whereIn('id', $ids)->get()->keyBy('id');

        $describe = function (?int $id) use ($locations) {
            if ($id === null) {
                return null;
            }

            /** @var BdLocation $location */
            $location = $locations[$id];

            return ['en' => $location->name_en, 'bn' => $location->name_bn];
        };

        return [
            'division' => $describe($divisionId),
            'district' => $describe($districtId),
            'upazila' => $describe($upazilaId),
            'union' => $describe($unionId),
        ];
    }
}
