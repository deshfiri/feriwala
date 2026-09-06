<?php

namespace App\Domain\Kyc\Models;

use App\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Database\Factories\KycDocumentTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A document an administrator asks applicants for (§7.2).
 *
 * Three states, not two. **Active** is offered on the form; **inactive** is
 * paused and can be turned back on; **archived** is retired for good. Deleting
 * a type that a submission references would leave a reviewed round describing
 * a requirement nobody can name any more, so archiving is what happens instead
 * (§7.3, §36.2).
 *
 * @property string $key
 * @property string $name
 * @property string|null $instructions
 * @property bool $is_required
 * @property bool $is_active
 * @property CarbonImmutable|null $archived_at
 * @property array<int, string> $accepted_mime_types
 * @property int $max_size_kb
 * @property int $sort_order
 */
class KycDocumentType extends Model
{
    /** @use HasFactory<KycDocumentTypeFactory> */
    use HasFactory, HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_mime_types' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'archived_at' => 'immutable_datetime',
            'requires_file' => 'boolean',
            'requires_value' => 'boolean',
            'max_size_kb' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function newFactory(): KycDocumentTypeFactory
    {
        return KycDocumentTypeFactory::new();
    }

    /**
     * @return HasMany<KycDocumentTypeScope, $this>
     */
    public function scopes(): HasMany
    {
        return $this->hasMany(KycDocumentTypeScope::class);
    }

    /**
     * @return HasMany<KycDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(KycDocument::class, 'kyc_document_type_id');
    }

    /**
     * @return HasMany<KycSubmissionRequirement, $this>
     */
    public function submissionRequirements(): HasMany
    {
        return $this->hasMany(KycSubmissionRequirement::class, 'kyc_document_type_id');
    }

    /**
     * Offered on the form right now: active and not retired.
     *
     * @param  Builder<KycDocumentType>  $query
     * @return Builder<KycDocumentType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @param  Builder<KycDocumentType>  $query
     * @return Builder<KycDocumentType>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Whether any round has ever been opened against this type.
     *
     * The question that decides delete versus archive: a type nothing refers to
     * is a configuration mistake being tidied away; one a submission names is
     * part of a record that has to stay readable.
     */
    public function isReferenced(): bool
    {
        return $this->submissionRequirements()->exists() || $this->documents()->exists();
    }

    /**
     * The rule that governs an account on `$package` in `$country`, or null
     * when this type does not apply to them at all (§7.2).
     *
     * A type with **no** scopes is global and applies to everyone — the common
     * case, and the safe fallback. A type that carries scopes applies only when
     * one matches; the most specific match wins, and ties go to the newer rule.
     *
     * @param  Collection<int, KycDocumentTypeScope>|null  $scopes
     */
    public function ruleFor(?string $package, ?string $country, $scopes = null): ?KycDocumentTypeScope
    {
        $scopes ??= $this->scopes;

        if ($scopes->isEmpty()) {
            return null;
        }

        return $scopes
            ->filter(fn (KycDocumentTypeScope $scope) => $scope->matches($package, $country))
            ->sortByDesc(fn (KycDocumentTypeScope $scope) => [$scope->specificity(), $scope->id])
            ->first();
    }

    /**
     * Whether this type applies to an account on the given package in the given
     * country (§7.2).
     *
     * @param  Collection<int, KycDocumentTypeScope>|null  $scopes
     */
    public function appliesTo(?string $package, ?string $country, $scopes = null): bool
    {
        $scopes ??= $this->scopes;

        return $scopes->isEmpty() || $this->ruleFor($package, $country, $scopes) !== null;
    }

    /**
     * Whether it is mandatory for them, honouring a scope override.
     *
     * @param  Collection<int, KycDocumentTypeScope>|null  $scopes
     */
    public function isRequiredFor(?string $package, ?string $country, $scopes = null): bool
    {
        $rule = $this->ruleFor($package, $country, $scopes);

        // Two different nulls, and both mean "use the type's own setting": no
        // rule matched at all, or a rule matched and stated no override.
        if ($rule === null || $rule->is_required === null) {
            return $this->is_required;
        }

        return $rule->is_required;
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
