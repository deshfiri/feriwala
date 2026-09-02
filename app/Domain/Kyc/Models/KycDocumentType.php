<?php

namespace App\Domain\Kyc\Models;

use App\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A document an administrator asks applicants for (§7.2).
 *
 * @property array<int, string> $accepted_mime_types
 */
class KycDocumentType extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'accepted_mime_types' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'requires_file' => 'boolean',
            'requires_value' => 'boolean',
            'max_size_kb' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<KycDocumentTypeScope, $this>
     */
    public function scopes(): HasMany
    {
        return $this->hasMany(KycDocumentTypeScope::class);
    }

    /**
     * @param  Builder<KycDocumentType>  $query
     * @return Builder<KycDocumentType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Whether this type applies to an account on the given package in the given
     * country (§7.2).
     *
     * A type with no scopes applies to everyone — the common case. Where scopes
     * exist, the type applies when **any** matches, so "required for Bangladesh
     * or for the Enterprise package" behaves as an administrator would expect.
     *
     * @param  Collection<int, KycDocumentTypeScope>|null  $scopes
     */
    public function appliesTo(?string $packageKey, ?string $country, $scopes = null): bool
    {
        $scopes ??= $this->scopes;

        if ($scopes->isEmpty()) {
            return true;
        }

        foreach ($scopes as $scope) {
            $matches = match ($scope->scope_type) {
                'package' => $packageKey !== null && $scope->scope_value === $packageKey,
                'country' => $country !== null && $scope->scope_value === $country,
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an uploaded file is acceptable for this type.
     */
    public function accepts(string $mimeType, int $sizeBytes): bool
    {
        return in_array($mimeType, $this->accepted_mime_types, true)
            && $sizeBytes <= $this->max_size_kb * 1024;
    }
}
