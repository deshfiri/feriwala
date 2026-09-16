<?php

namespace App\Domain\Website\Models;

use App\Concerns\AppendOnlyStatusHistory;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteStatusChangeSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to a website's status (§16.4, P5-9). Append-only.
 *
 * The internal note is for the people running the platform; only the public
 * note reaches the partner whose storefront it is.
 *
 * @property int $id
 * @property int $website_id
 * @property WebsiteStatus|null $previous_status
 * @property WebsiteStatus $new_status
 * @property WebsiteStatusChangeSource $source
 * @property int|null $changed_by
 * @property CarbonImmutable $changed_at
 * @property string|null $reason
 * @property string|null $internal_note
 * @property string|null $public_note
 * @property-read Website $website
 * @property-read User|null $changedBy
 */
class WebsiteStatusChange extends Model
{
    use AppendOnlyStatusHistory;

    protected $table = 'website_status_history';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_status' => WebsiteStatus::class,
            'new_status' => WebsiteStatus::class,
            'source' => WebsiteStatusChangeSource::class,
        ];
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
