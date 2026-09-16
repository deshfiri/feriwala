<?php

namespace App\Domain\Website\Models;

use App\Concerns\HasPublicId;
use App\Domain\Website\Enums\CredentialScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One storefront's proof of who it is (contract §3.1, §17.3, P5-17).
 *
 * Belongs to exactly one website, and that is the whole tenancy model: every
 * API query is scoped to this website before any caller-supplied filter runs
 * (contract §3.5). The website it belongs to and its key identifier are locked
 * once written.
 *
 * The secret is encrypted at rest and hidden from serialisation. It is never
 * put on a page after the moment it is issued.
 *
 * @property int $id
 * @property string $public_id
 * @property int $website_id
 * @property string $name
 * @property string $key_id
 * @property string $secret
 * @property string $secret_hint
 * @property string|null $previous_secret
 * @property CarbonImmutable|null $previous_secret_expires_at
 * @property array<int, string> $scopes
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $rotated_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_reason
 * @property int|null $created_by
 * @property int|null $revoked_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Website $website
 * @property-read User|null $createdBy
 * @property-read User|null $revokedBy
 */
class WebsiteCredential extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * Never serialised. A secret that reaches a JSON response through a
     * relation somebody forgot to trim is a leaked secret.
     *
     * @var list<string>
     */
    protected $hidden = ['secret', 'previous_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'immutable_datetime',
            'scopes' => 'array',
            'last_used_at' => 'immutable_datetime',
            'rotated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function allows(CredentialScope $scope): bool
    {
        return in_array($scope->value, $this->scopes, true);
    }

    /**
     * The secrets a signature may have been made with, right now.
     *
     * The current one, and the previous one while its grace window is open —
     * so a storefront mid-redeploy after a rotation is not locked out.
     *
     * @return array<int, string>
     */
    public function acceptedSecrets(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $secrets = [$this->secret];

        if ($this->previous_secret !== null
            && $this->previous_secret_expires_at !== null
            && $this->previous_secret_expires_at->isAfter($now)) {
            $secrets[] = $this->previous_secret;
        }

        return $secrets;
    }

    /**
     * Credentials that still work.
     *
     * @param  Builder<WebsiteCredential>  $query
     * @return Builder<WebsiteCredential>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
