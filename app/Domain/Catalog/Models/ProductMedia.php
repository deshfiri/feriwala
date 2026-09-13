<?php

namespace App\Domain\Catalog\Models;

use App\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image or video of a product (§11.1).
 *
 * @property int $id
 * @property string $public_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $type
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt_text
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
class ProductMedia extends Model
{
    use HasPublicId;

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    protected $table = 'product_media';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'position' => 'integer',
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
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
