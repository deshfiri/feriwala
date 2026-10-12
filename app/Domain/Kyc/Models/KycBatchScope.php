<?php

namespace App\Domain\Kyc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rule saying who a batch applies to: a package, a country, or both.
 *
 * A null dimension matches anything, so a rule naming only a country applies on
 * every package there. A rule naming neither is not stored — that is what a
 * batch with no rules already means.
 */
class KycBatchScope extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<KycBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(KycBatch::class, 'kyc_batch_id');
    }

    public function matches(?string $package, ?string $country): bool
    {
        return ($this->package_public_id === null || $this->package_public_id === $package)
            && ($this->country_code === null || ($country !== null && mb_strtoupper($this->country_code) === mb_strtoupper($country)));
    }
}
