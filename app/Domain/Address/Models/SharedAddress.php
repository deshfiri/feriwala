<?php

namespace App\Domain\Address\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Domain\Address\Actions\SaveSharedAddress;
use App\Domain\Address\Data\AddressSnapshot;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Location\Models\BdLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One address in the shared, polymorphic address book — a `BusinessAccount`'s
 * business/operational address, or a Supplier's registered/pickup/return
 * address (see the migration's own docblock for why one table holds both).
 *
 * `location_snapshot` is frozen at save time by
 * {@see SaveSharedAddress}, so a later rename or
 * deactivation of a `bd_locations` row never changes what this address
 * already says.
 *
 * @property int $id
 * @property string $public_id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $type
 * @property AddressStatus $status
 * @property string $contact_name
 * @property string $contact_mobile
 * @property int $division_id
 * @property int $district_id
 * @property int $upazila_id
 * @property int|null $union_id
 * @property string $detailed_address
 * @property string|null $landmark
 * @property string|null $postcode
 * @property array<string, mixed> $location_snapshot
 * @property bool $is_default
 */
class SharedAddress extends Model
{
    use HasPublicId, HasStateMachine;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AddressStatus::class,
            'location_snapshot' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<BdLocation, $this>
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(BdLocation::class, 'division_id');
    }

    /**
     * @return BelongsTo<BdLocation, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(BdLocation::class, 'district_id');
    }

    /**
     * @return BelongsTo<BdLocation, $this>
     */
    public function upazila(): BelongsTo
    {
        return $this->belongsTo(BdLocation::class, 'upazila_id');
    }

    /**
     * @return BelongsTo<BdLocation, $this>
     */
    public function union(): BelongsTo
    {
        return $this->belongsTo(BdLocation::class, 'union_id');
    }

    public function ownedBy(AddressOwnerType $type, int $ownerId): bool
    {
        return $this->owner_type === $type->value && $this->owner_id === $ownerId;
    }

    /**
     * An immutable copy of this address, for whatever future consumer needs
     * to keep what it said at the moment it was used rather than a live
     * reference to a row that may change or be archived later.
     */
    public function toSnapshot(): AddressSnapshot
    {
        return new AddressSnapshot(
            type: $this->type,
            contactName: $this->contact_name,
            contactMobile: $this->contact_mobile,
            detailedAddress: $this->detailed_address,
            landmark: $this->landmark,
            postcode: $this->postcode,
            locationSnapshot: $this->location_snapshot,
        );
    }
}
