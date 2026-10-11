<?php

namespace App\Domain\ContentLibrary\Models;

use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One piece of content in the Content Library: a title and an ordered list of
 * blocks (text, image, video, link), released to one or more Products.
 *
 * Blocks are structured data, not HTML — see {@see ContentBlocks}.
 *
 * @property int $id
 * @property string $public_id
 * @property string $title
 * @property array<int, array<string, mixed>> $blocks
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable $published_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read User|null $creator
 */
class ContentLibraryItem extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'content_library_item_product')->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
