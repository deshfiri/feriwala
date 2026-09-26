<?php

namespace App\Domain\Cms\Models;

use App\Concerns\HasPublicId;
use App\Domain\Cms\Enums\SectionKind;
use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A page's live, editable section (§4, §34). Never read by the public
 * reader — see {@see PageRevision} for the frozen, publicly-served copy.
 *
 * @property int $id
 * @property string $public_id
 * @property int $cms_page_id
 * @property string $section_key
 * @property SectionKind $kind
 * @property int $sort_order
 * @property bool $is_enabled
 * @property bool $visible_on_desktop
 * @property bool $visible_on_mobile
 * @property string|null $variant
 * @property ArrayObject<string, mixed> $content
 * @property ArrayObject<string, mixed>|null $color_overrides
 */
class PageSection extends Model
{
    use HasPublicId;

    protected $table = 'cms_page_sections';

    protected $fillable = [
        'cms_page_id',
        'section_key',
        'kind',
        'sort_order',
        'is_enabled',
        'visible_on_desktop',
        'visible_on_mobile',
        'variant',
        'content',
        'color_overrides',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SectionKind::class,
            'is_enabled' => 'boolean',
            'visible_on_desktop' => 'boolean',
            'visible_on_mobile' => 'boolean',
            'content' => AsArrayObject::class,
            'color_overrides' => AsArrayObject::class,
        ];
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'cms_page_id');
    }
}
