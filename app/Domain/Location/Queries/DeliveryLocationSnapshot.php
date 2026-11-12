<?php

namespace App\Domain\Location\Queries;

use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Models\BdLocation;

/**
 * A delivery place chosen Division -> District -> Upazila (-> Union), as an
 * address an order keeps.
 *
 * The chain is checked here, not trusted from the form: each place must exist,
 * be active, and sit inside the one above it, so a district of another division
 * cannot be posted. The order keeps the names as they were, in both languages'
 * English form, so it still reads the same after the directory is re-imported.
 */
class DeliveryLocationSnapshot
{
    /**
     * @return array<string, string|null>|null the address parts, or null when the chain is not a real one
     */
    public function for(int $divisionId, int $districtId, int $upazilaId, ?int $unionId, string $line1, ?string $postcode): ?array
    {
        $division = $this->place($divisionId, BdLocationType::Division, null);
        $district = $division === null ? null : $this->place($districtId, BdLocationType::District, $division);
        $upazila = $district === null ? null : $this->place($upazilaId, BdLocationType::Upazila, $district);
        $union = $upazila === null || $unionId === null ? null : $this->place($unionId, BdLocationType::Union, $upazila);

        if ($upazila === null || ($unionId !== null && $union === null)) {
            return null;
        }

        return [
            'line1' => trim($line1),
            'line2' => null,
            'city' => $upazila->name_en,
            'district' => $district->name_en,
            'postcode' => $postcode !== null && trim($postcode) !== '' ? trim($postcode) : null,
            'country' => 'BD',
            'division' => $division->name_en,
            'upazila' => $upazila->name_en,
            'union' => $union?->name_en,
            'division_id' => (string) $division->id,
            'district_id' => (string) $district->id,
            'upazila_id' => (string) $upazila->id,
            'union_id' => $union === null ? null : (string) $union->id,
        ];
    }

    protected function place(int $id, BdLocationType $type, ?BdLocation $parent): ?BdLocation
    {
        return BdLocation::query()
            ->ofType($type)
            ->where('id', $id)
            ->where('is_active', true)
            ->when($parent !== null, fn ($query) => $query->where('parent_id', $parent->id))
            ->first();
    }
}
