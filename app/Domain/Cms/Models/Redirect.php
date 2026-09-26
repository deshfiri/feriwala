<?php

namespace App\Domain\Cms\Models;

use App\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property string $from_path
 * @property string $to_path
 * @property int $status_code
 * @property bool $is_enabled
 */
class Redirect extends Model
{
    use HasPublicId;

    protected $table = 'cms_redirects';

    protected $fillable = ['from_path', 'to_path', 'status_code', 'is_enabled', 'created_by'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The one lookup the public site's fallback route needs (§34.1) — an
     * exact, enabled match for the current request path.
     *
     * @param  Builder<Redirect>  $query
     * @return Builder<Redirect>
     */
    public function scopeEnabledFor(Builder $query, string $path): Builder
    {
        return $query->where('is_enabled', true)->where('from_path', $path);
    }
}
