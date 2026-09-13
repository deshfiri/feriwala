<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move of a product's status on one axis (§11.2). Append-only.
 *
 * @property int $id
 * @property int $product_id
 * @property string $axis
 * @property ProductStatus|null $from_status
 * @property ProductStatus $to_status
 * @property int|null $actor_id
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 * @property-read User|null $actor
 */
class ProductStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'product_status_history';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => ProductStatus::class,
            'to_status' => ProductStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
