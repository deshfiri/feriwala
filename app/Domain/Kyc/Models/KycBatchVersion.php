<?php

namespace App\Domain\Kyc\Models;

use App\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A batch exactly as it stood when it was published (D30).
 *
 * Append-only in the database. A round points at one of these and reads its
 * structure from `definition` alone, so no later edit to the live batch — a
 * renamed group, a looser file limit, a removed option — can reach a round that
 * is already open or already judged.
 *
 * @property int $kyc_batch_id
 * @property int $version
 * @property array<string, mixed> $definition
 * @property string $checksum
 * @property CarbonImmutable $published_at
 */
class KycBatchVersion extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'version' => 'integer',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<KycBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(KycBatch::class, 'kyc_batch_id');
    }
}
