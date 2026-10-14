<?php

namespace App\Domain\Kyc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A requirement's place in a group: whether it is owed (in an "all" group) and
 * where it sits (D30).
 *
 * @property bool $is_required
 * @property int $sort_order
 */
class KycBatchGroupRequirement extends Model
{
    protected $table = 'kyc_batch_group_requirements';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<KycBatchGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(KycBatchGroup::class, 'kyc_batch_group_id');
    }

    /**
     * @return BelongsTo<KycDocumentType, $this>
     */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(KycDocumentType::class, 'kyc_document_type_id');
    }
}
