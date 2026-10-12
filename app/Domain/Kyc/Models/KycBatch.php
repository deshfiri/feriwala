<?php

namespace App\Domain\Kyc\Models;

use App\Concerns\HasPublicId;
use App\Domain\Kyc\Enums\KycAudience;
use App\Domain\Supplier\Models\SupplierKycRequirement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A batch of verification criteria an administrator asks for together (§7.2).
 *
 * "Identity" holds the NID, the passport and the selfie; "Business" the trade
 * licence and TIN. The administrator creates as many batches as they like and
 * decides what each holds — nothing here is built in. An applicant sees every
 * batch that applies to them on one form and completes them in one submission;
 * there is no stepping through rounds.
 *
 * The country and package rules belong to the batch, not to each criterion in
 * it. A batch with no rules applies to everyone; with rules, to whoever matches
 * any one of them.
 *
 * Like a criterion, a batch is archived rather than deleted once anything has
 * been asked of anyone through it, so a reviewed submission can still say what
 * it was judged against.
 *
 * @property string $key
 * @property KycAudience $audience
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property CarbonImmutable|null $archived_at
 * @property int $sort_order
 */
class KycBatch extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => KycAudience::class,
            'is_active' => 'boolean',
            'archived_at' => 'immutable_datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The criteria in this batch.
     *
     * @return HasMany<KycDocumentType, $this>
     */
    public function types(): HasMany
    {
        return $this->hasMany(KycDocumentType::class, 'kyc_batch_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return HasMany<KycBatchScope, $this>
     */
    public function scopes(): HasMany
    {
        return $this->hasMany(KycBatchScope::class, 'kyc_batch_id');
    }

    public function scopeForAccounts(Builder $query): Builder
    {
        return $query->whereIn('audience', [KycAudience::Account->value, KycAudience::Both->value]);
    }

    public function scopeForSuppliers(Builder $query): Builder
    {
        return $query->whereIn('audience', [KycAudience::Supplier->value, KycAudience::Both->value]);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Whether anything has been asked through this batch. A batch that has been
     * is archived, not deleted.
     */
    public function isReferenced(): bool
    {
        return $this->types()->exists()
            || KycSubmissionRequirement::query()->where('kyc_batch_id', $this->id)->exists()
            || SupplierKycRequirement::query()->where('kyc_batch_id', $this->id)->exists();
    }

    /**
     * Whether an applicant on `$package` in `$country` is asked for this batch.
     *
     * No rules means everyone. With rules, any one that matches is enough, and a
     * rule that names only a country matches on every package there.
     *
     * @param  string|null  $package  the account's package public id
     * @param  Collection<int, KycBatchScope>|null  $scopes  pre-loaded, to avoid a query per batch
     */
    public function appliesTo(?string $package, ?string $country, ?Collection $scopes = null): bool
    {
        if (! $this->is_active || $this->isArchived()) {
            return false;
        }

        $scopes ??= $this->scopes;

        return $scopes->isEmpty()
            || $scopes->contains(fn (KycBatchScope $scope) => $scope->matches($package, $country));
    }
}
