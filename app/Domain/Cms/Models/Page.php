<?php

namespace App\Domain\Cms\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Domain\Cms\Enums\PagePublicationState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A public-site page (§4, §34). Its own row tracks identity, lifecycle, and
 * a draft-side SEO override only — everything else a visitor reads lives in
 * whichever revision `currentPublishedRevision` points at (see
 * {@see PageRevision}).
 *
 * @property int $id
 * @property string $public_id
 * @property string $slug
 * @property string $page_type
 * @property string $default_locale
 * @property PagePublicationState $publication_state
 * @property CarbonImmutable|null $scheduled_publish_at
 * @property CarbonImmutable|null $scheduled_unpublish_at
 * @property int|null $current_published_revision_id
 * @property ArrayObject<string, mixed>|null $seo_overrides
 */
class Page extends Model
{
    use HasPublicId;
    use HasStateMachine;

    protected $table = 'cms_pages';

    protected $fillable = [
        'slug',
        'page_type',
        'default_locale',
        'publication_state',
        'scheduled_publish_at',
        'scheduled_unpublish_at',
        'current_published_revision_id',
        'seo_overrides',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'publication_state' => PagePublicationState::class,
            'scheduled_publish_at' => 'immutable_datetime',
            'scheduled_unpublish_at' => 'immutable_datetime',
            'seo_overrides' => AsArrayObject::class,
        ];
    }

    public function stateAttribute(): string
    {
        return 'publication_state';
    }

    /**
     * @return HasMany<PageSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(PageSection::class, 'cms_page_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<PageRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class, 'cms_page_id')->orderByDesc('version');
    }

    /**
     * @return BelongsTo<PageRevision, $this>
     */
    public function currentPublishedRevision(): BelongsTo
    {
        return $this->belongsTo(PageRevision::class, 'current_published_revision_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
