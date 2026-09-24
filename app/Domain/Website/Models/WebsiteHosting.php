<?php

namespace App\Domain\Website\Models;

use App\Casts\MoneyCast;
use App\Domain\Website\Enums\WebsiteServiceStatus;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\WebsiteHostingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hosting term bought for a website (§16.2, §41, P5-11).
 *
 * The provider is an internal detail and never leaves the platform: a partner
 * is told when their hosting runs out, not where their shop is hosted.
 *
 * @property int $id
 * @property int $website_id
 * @property string $plan
 * @property string|null $provider
 * @property WebsiteServiceStatus $status
 * @property string $currency_code
 * @property Money $fee
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $expires_at
 * @property bool $auto_renew
 * @property CarbonImmutable|null $reminded_at
 * @property int|null $reminder_stage
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Website $website
 * @property-read User|null $createdBy
 */
class WebsiteHosting extends Model
{
    /** @use HasFactory<WebsiteHostingFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebsiteServiceStatus::class,
            'fee' => MoneyCast::class,
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'auto_renew' => 'boolean',
            'reminded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Domain models live deeper than the factory naming convention expects, so
     * the binding is stated rather than guessed.
     */
    protected static function newFactory(): WebsiteHostingFactory
    {
        return WebsiteHostingFactory::new();
    }

    /**
     * How many days are left on the term, or null when it has none.
     */
    public function daysRemaining(?CarbonImmutable $now = null): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) ($now ?? CarbonImmutable::now())->startOfDay()
            ->diffInDays($this->expires_at->startOfDay(), absolute: false);
    }

    /**
     * Terms running out on or before a date.
     *
     * @param  Builder<WebsiteHosting>  $query
     * @return Builder<WebsiteHosting>
     */
    public function scopeExpiringBy(Builder $query, CarbonImmutable $date): Builder
    {
        return $query->where('status', WebsiteServiceStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $date);
    }
}
