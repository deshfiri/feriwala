<?php

namespace App\Domain\Package\Models;

use App\Domain\Package\Enums\PackageFeature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entitlement value on a package (§8.1).
 *
 * Named `PackageFeatureValue` rather than `PackageFeature` so it does not
 * collide with the enum of the same concept — the enum names *which* feature,
 * this row holds *what* it is set to.
 */
class PackageFeatureValue extends Model
{
    protected $table = 'package_features';

    protected $guarded = [];

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function toFeature(): ?PackageFeature
    {
        return PackageFeature::tryFrom($this->feature);
    }

    /**
     * The typed value, or the feature's default when unset.
     */
    public function typed(): bool|int|string|null
    {
        $feature = $this->toFeature();

        if ($feature === null) {
            return null;
        }

        return $feature->type()->cast($this->value);
    }
}
