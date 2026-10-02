<?php

namespace App\Domain\Catalog\Models;

use App\Concerns\HasPublicId;
use App\Domain\Storage\Models\StoredFile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * One update an administrator published for a product: a title, an optional
 * body, and at most one attachment. Published immediately — there is no
 * draft or schedule here, only a feed every operating partner may read.
 *
 * @property int $id
 * @property string $public_id
 * @property int $product_id
 * @property int|null $created_by
 * @property string $title
 * @property string|null $body
 * @property CarbonImmutable $published_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Product $product
 * @property-read User|null $creator
 * @property-read StoredFile|null $attachment
 */
class ProductContent extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return MorphOne<StoredFile, $this>
     */
    public function attachment(): MorphOne
    {
        return $this->morphOne(StoredFile::class, 'fileable');
    }
}
