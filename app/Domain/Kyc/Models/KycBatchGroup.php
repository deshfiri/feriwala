<?php

namespace App\Domain\Kyc\Models;

use App\Concerns\HasPublicId;
use App\Domain\Kyc\Enums\KycCompletionRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One group of requirements inside a batch, with its own completion rule (D30).
 *
 * @property string $key
 * @property string $name
 * @property string|null $name_bn
 * @property string|null $description
 * @property string|null $description_bn
 * @property KycCompletionRule $completion_rule
 * @property int|null $minimum_required
 * @property bool $is_required
 * @property int $sort_order
 */
class KycBatchGroup extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completion_rule' => KycCompletionRule::class,
            'minimum_required' => 'integer',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<KycBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(KycBatch::class, 'kyc_batch_id');
    }

    /**
     * @return HasMany<KycBatchGroupRequirement, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(KycBatchGroupRequirement::class, 'kyc_batch_group_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
