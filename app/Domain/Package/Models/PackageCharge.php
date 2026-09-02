<?php

namespace App\Domain\Package\Models;

use App\Casts\MoneyCast;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service charge attached to a package (§8.1, §16.2).
 *
 * @property Money $amount_minor
 */
class PackageCharge extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function isRecurring(): bool
    {
        return $this->frequency !== 'once';
    }
}
