<?php

namespace App\Domain\Storage\Models;

use App\Concerns\HasPublicId;
use App\Domain\Storage\Enums\StorageVisibility;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One file written through the shared managed-storage abstraction
 * (beta-critical batch, Commit 4): where it actually lives, what it is, and
 * whether it may be addressed directly.
 *
 * @property int $id
 * @property string $public_id
 * @property string $purpose
 * @property string|null $fileable_type
 * @property int|null $fileable_id
 * @property string $disk
 * @property string $path
 * @property StorageVisibility $visibility
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $checksum
 * @property bool $is_encrypted
 * @property CarbonImmutable|null $retain_until
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $creator
 */
class StoredFile extends Model
{
    use HasPublicId;

    protected $table = 'stored_files';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => StorageVisibility::class,
            'size_bytes' => 'integer',
            'is_encrypted' => 'boolean',
            'retain_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether retention still refuses a delete.
     */
    public function isRetained(): bool
    {
        return $this->retain_until !== null && $this->retain_until->isFuture();
    }
}
