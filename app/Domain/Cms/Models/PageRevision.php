<?php

namespace App\Domain\Cms\Models;

use App\Concerns\HasPublicId;
use App\Domain\Cms\Enums\RevisionPublicationState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable snapshot of a page, taken the moment it was published or
 * scheduled (§34.1). Guarded append-only by a database trigger — see the
 * migration — so this class deliberately has no `update()`/`delete()`
 * call anywhere in the codebase; a correction is always a new revision.
 *
 * @property int $id
 * @property string $public_id
 * @property int $cms_page_id
 * @property int $version
 * @property ArrayObject<string, mixed> $content
 * @property RevisionPublicationState $publication_state
 * @property CarbonImmutable|null $published_at
 * @property int|null $restored_from_id
 */
class PageRevision extends Model
{
    use HasPublicId;

    protected $table = 'cms_page_revisions';

    protected $fillable = [
        'cms_page_id',
        'version',
        'content',
        'publication_state',
        'published_at',
        'restored_from_id',
        'created_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'content' => AsArrayObject::class,
            'publication_state' => RevisionPublicationState::class,
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'cms_page_id');
    }

    /**
     * @return BelongsTo<PageRevision, $this>
     */
    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restored_from_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
