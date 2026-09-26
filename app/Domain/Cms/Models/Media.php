<?php

namespace App\Domain\Cms\Models;

use App\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A CMS-uploaded asset (§4, §34). `path` is a random storage path, never the
 * original filename — {@see url()} is the only supported way to reach the
 * file publicly, so nothing depends on the storage layout directly.
 *
 * @property int $id
 * @property string $public_id
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt_text_en
 * @property string|null $alt_text_bn
 * @property string|null $attribution
 */
class Media extends Model
{
    use HasPublicId;

    protected $table = 'cms_media';

    protected $fillable = [
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'alt_text_en',
        'alt_text_bn',
        'attribution',
        'uploaded_by',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function altText(string $locale): ?string
    {
        return $locale === 'bn' ? $this->alt_text_bn : $this->alt_text_en;
    }

    /**
     * Whether this asset is ready to appear on a published page — every
     * meaningful image needs alt text in both locales before it may be
     * placed (§34's content-safety requirement).
     */
    public function hasRequiredAltText(): bool
    {
        return filled($this->alt_text_en) && filled($this->alt_text_bn);
    }
}
